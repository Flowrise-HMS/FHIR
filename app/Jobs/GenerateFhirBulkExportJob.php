<?php

namespace Modules\FHIR\Jobs;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\FHIR\Enums\FhirExportStatus;
use Modules\FHIR\FhirBundle\BulkExporter;
use Modules\FHIR\Models\FhirExportJob;
use Throwable;

class GenerateFhirBulkExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * One attempt only. A partial retry would append to files already written by
     * the failed run, producing duplicate resources in the output.
     */
    public function __construct(public string $exportJobId)
    {
        $this->onQueue(config('fhir.export.queue', 'default'));
    }

    public function handle(BulkExporter $exporter): void
    {
        $job = FhirExportJob::query()->find($this->exportJobId);

        if (! $job || $job->status !== FhirExportStatus::PENDING) {
            return;
        }

        /*
         * Re-establish branch context before touching any model.
         *
         * A queued worker has no request and no authenticated user, so
         * BelongsToBranch's global scope would find a null branch id and apply no
         * filter at all — quietly exporting every branch. The whole point of
         * recording branch_id on the job row is this line.
         */
        Context::add('current_branch_id', $job->branch_id);

        $job->update([
            'status' => FhirExportStatus::RUNNING,
            'transaction_time' => now(),
        ]);

        try {
            $job->update([
                'status' => FhirExportStatus::COMPLETED,
                'output' => $this->writeFiles($exporter, $job),
                'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('fhir.export.retention_days', 7)),
            ]);

            $this->notifyCompleted($job->fresh());
        } catch (Throwable $e) {
            // Leave nothing half-written for a client to download as if complete.
            $job->deleteFiles();

            $job->update([
                'status' => FhirExportStatus::FAILED,
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $this->notifyFailed($job->fresh());

            throw $e;
        } finally {
            Context::forget('current_branch_id');
        }
    }

    /**
     * Tell the requester their export is ready.
     *
     * The action's confirmation modal promises "you will be notified when it is
     * ready", so this is not a nicety — without it the UI makes a promise the
     * system does not keep, and an export that succeeded looks identical to one
     * that silently never ran.
     */
    private function notifyCompleted(FhirExportJob $job): void
    {
        $requester = $job->requester;

        if (! $requester) {
            return;
        }

        $count = $job->resourceCount();

        $notification = Notification::make()
            ->title('FHIR export ready')
            ->success()
            ->body(sprintf(
                '%s %s exported. The file is available until %s.',
                number_format($count),
                $count === 1 ? 'resource' : 'resources',
                $job->expires_at?->toDayDateTimeString() ?? 'it is pruned',
            ));

        $actions = collect($job->output ?? [])
            ->map(fn (array $file): Action => Action::make("download_{$file['type']}")
                ->label("Download {$file['type']}")
                ->url($file['url'])
                ->openUrlInNewTab()
            )
            ->all();

        if ($actions !== []) {
            $notification->actions($actions);
        }

        $notification->sendToDatabase($requester, isEventDispatched: true);
    }

    private function notifyFailed(FhirExportJob $job): void
    {
        $requester = $job->requester;

        if (! $requester) {
            return;
        }

        Notification::make()
            ->title('FHIR export failed')
            ->danger()
            ->body($job->error ?: 'The export could not be completed.')
            ->sendToDatabase($requester, isEventDispatched: true);
    }

    /**
     * Write one NDJSON file per resource type and return the manifest entries.
     *
     * Types that yield no rows produce no file and no manifest entry, per the Bulk
     * Data specification — an empty file would imply "checked, nothing there" is
     * distinguishable from "not requested", which it is not.
     *
     * @return list<array{type: string, url: string, count: int}>
     */
    private function writeFiles(BulkExporter $exporter, FhirExportJob $job): array
    {
        $disk = Storage::disk($job->disk());
        $output = [];

        foreach ($job->types ?: $exporter->exportableTypes() as $resourceType) {
            $path = $job->path($resourceType);
            $count = 0;

            foreach ($this->linesFor($exporter, $job, $resourceType) as $line) {
                if ($count === 0) {
                    $disk->put($path, $line);
                } else {
                    $disk->append($path, rtrim($line, "\n"));
                }

                $count++;
            }

            if ($count === 0) {
                continue;
            }

            $output[] = [
                'type' => $resourceType,
                // Signed so the "export ready" notification works from a plain
                // browser session; expires with the file's retention window.
                'url' => URL::temporarySignedRoute(
                    'api.fhir.export.file',
                    now()->addDays((int) config('fhir.export.retention_days', 7)),
                    ['export' => $job->id, 'type' => $resourceType],
                ),
                'count' => $count,
            ];
        }

        return $output;
    }

    /**
     * NDJSON lines for one resource type.
     *
     * An export started from a Filament table carries a serialized builder so the
     * user's filters are honoured; one started from the API has none and covers the
     * whole type. Deserialization is guarded: a builder serialized against an older
     * schema should degrade to a full export of that type rather than fail the job.
     *
     * @return iterable<int, string>
     */
    private function linesFor(BulkExporter $exporter, FhirExportJob $job, string $resourceType): iterable
    {
        $since = $job->since?->toIso8601String();

        if ($job->filtered_query === null) {
            return $exporter->stream([$resourceType], $since);
        }

        try {
            $query = EloquentSerializeFacade::unserialize($job->filtered_query);
        } catch (Throwable $e) {
            Log::warning('FHIR export could not restore its filtered query; exporting the full type.', [
                'export_job_id' => $job->id,
                'resource_type' => $resourceType,
                'error' => $e->getMessage(),
            ]);

            return $exporter->stream([$resourceType], $since);
        }

        return $exporter->streamQuery($resourceType, $query, $since);
    }

    public function failed(Throwable $e): void
    {
        $job = FhirExportJob::query()->find($this->exportJobId);

        if ($job && $job->status->isInProgress()) {
            $job->deleteFiles();
            $job->update([
                'status' => FhirExportStatus::FAILED,
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
