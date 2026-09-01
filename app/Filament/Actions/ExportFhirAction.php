<?php

namespace Modules\FHIR\Filament\Actions;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Modules\Core\Support\CurrentBranch;
use Modules\FHIR\Enums\FhirExportStatus;
use Modules\FHIR\Jobs\GenerateFhirBulkExportJob;
use Modules\FHIR\Models\FhirExportJob;

/**
 * "Export FHIR" actions for a resource list page.
 *
 * Two entry points, both producing the same asynchronous NDJSON export:
 *
 *  - {@see make()} — a header action covering the rows matching the user's current
 *    filters and search.
 *  - {@see bulk()} — a bulk action covering exactly the rows they ticked.
 *
 * Both carry a query to the worker rather than a list of models, using
 * `anourvalar/eloquent-serialize` — the mechanism Filament's own export pipeline
 * uses for the same problem.
 */
class ExportFhirAction
{
    public const PERMISSION = 'export_fhir';

    /**
     * Header action: export everything matching the current table filters.
     *
     * @param  string  $resourceType  the FHIR resource type this page's model maps to
     */
    public static function make(string $resourceType): Action
    {
        return Action::make('fhir.export')
            ->label('Export FHIR')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => (bool) Auth::user()?->can(self::PERMISSION))
            ->requiresConfirmation()
            ->modalHeading('Export as FHIR')
            ->modalDescription(
                'Exports the rows matching your current filters as FHIR NDJSON. '
                .'The file is prepared in the background; you will be notified when it is ready.'
            )
            ->modalSubmitActionLabel('Start export')
            ->action(fn (Component $livewire) => self::dispatch(
                $resourceType,
                self::filteredQuery($livewire),
            ));
    }

    /**
     * Bulk action: export only the selected rows.
     *
     * `accessSelectedRecords()` is required — without it Filament refuses to hand
     * the action its `$records` argument and throws at click time.
     */
    public static function bulk(string $resourceType): BulkAction
    {
        return BulkAction::make('fhir.export_selected')
            ->label('Export Selected as FHIR')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => (bool) Auth::user()?->can(self::PERMISSION))
            ->accessSelectedRecords()
            ->requiresConfirmation()
            ->modalHeading('Export selected as FHIR')
            ->modalSubmitActionLabel('Start export')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($resourceType): void {
                if ($records->isEmpty()) {
                    Notification::make()->title('Nothing selected')->warning()->send();

                    return;
                }

                /*
                 * Serialize a query keyed on the selected ids rather than the models
                 * themselves: the worker re-reads them under its own branch context,
                 * so a stale or out-of-scope selection cannot smuggle a row past the
                 * scoping that the export otherwise inherits.
                 */
                $model = $records->first();

                self::dispatch(
                    $resourceType,
                    EloquentSerializeFacade::serialize(
                        $model::query()->whereKey($records->modelKeys())
                    ),
                );
            });
    }

    private static function dispatch(string $resourceType, ?string $serializedQuery): void
    {
        $branchId = CurrentBranch::id();

        if ($branchId === null) {
            Notification::make()
                ->title('Select a branch first')
                ->body(
                    'An export belongs to a single branch. Your account is not tied to one, '
                    .'so choose the branch you want to export from using the branch switcher.'
                )
                ->warning()
                ->send();

            return;
        }

        $job = FhirExportJob::query()->create([
            'branch_id' => $branchId,
            'requested_by' => Auth::id(),
            'status' => FhirExportStatus::PENDING,
            'types' => [$resourceType],
            'filtered_query' => $serializedQuery,
        ]);

        GenerateFhirBulkExportJob::dispatch($job->id);

        Notification::make()
            ->title('Export started')
            ->body("Preparing your {$resourceType} export. You will be notified when it is ready.")
            ->success()
            ->send();
    }

    /**
     * Serialize the table's current query, or null to fall back to the whole type.
     *
     * `getTableQueryForExport()` is the query behind the rows on screen, filters and
     * all, and it is already branch-scoped by the panel.
     */
    private static function filteredQuery(Component $livewire): ?string
    {
        if (! $livewire instanceof HasTable) {
            return null;
        }

        $query = $livewire->getTableQueryForExport();

        return $query instanceof Builder
            ? EloquentSerializeFacade::serialize($query)
            : null;
    }
}
