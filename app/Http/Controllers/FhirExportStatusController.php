<?php

namespace Modules\FHIR\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Support\CurrentBranch;
use Modules\FHIR\Enums\FhirExportStatus;
use Modules\FHIR\FhirResponse\FhirResponseFactory;
use Modules\FHIR\Models\FhirExportJob;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Status, cancellation and file delivery for asynchronous bulk exports.
 *
 * Every action re-checks ownership rather than relying on a global scope: the
 * export row is deliberately not branch-scoped (see FhirExportJob), because the
 * caller needs a definite "not yours" rather than a silent miss, and the manifest
 * promises `requiresAccessToken: true` — a promise only this controller keeps.
 */
class FhirExportStatusController extends Controller
{
    public function __construct(protected FhirResponseFactory $responseFactory) {}

    public function show(string $export): JsonResponse
    {
        $job = $this->authorizedJob($export);

        if ($job instanceof JsonResponse) {
            return $job;
        }

        return match ($job->status) {
            FhirExportStatus::PENDING, FhirExportStatus::RUNNING => new JsonResponse(null, 202, [
                'X-Progress' => $job->status->value,
                'Retry-After' => '5',
            ]),
            FhirExportStatus::COMPLETED => new JsonResponse($this->manifest($job), 200, [
                'Content-Type' => 'application/fhir+json',
                'Expires' => $job->expires_at?->toRfc7231String() ?? '',
            ]),
            FhirExportStatus::CANCELLED => $this->outcome('Export was cancelled.', 404),
            FhirExportStatus::FAILED => $this->outcome($job->error ?: 'Export failed.', 500),
        };
    }

    /**
     * Cancel a running export and remove anything already written.
     */
    public function destroy(string $export): JsonResponse
    {
        $job = $this->authorizedJob($export);

        if ($job instanceof JsonResponse) {
            return $job;
        }

        if ($job->status->isTerminal()) {
            return $this->outcome('Export has already finished and cannot be cancelled.', 409);
        }

        $job->deleteFiles();
        $job->update(['status' => FhirExportStatus::CANCELLED, 'completed_at' => now()]);

        return new JsonResponse(null, 202);
    }

    /**
     * Stream one of the generated NDJSON files.
     */
    public function file(string $export, string $type): StreamedResponse|JsonResponse
    {
        $job = $this->authorizedJob($export);

        if ($job instanceof JsonResponse) {
            return $job;
        }

        if ($job->status !== FhirExportStatus::COMPLETED) {
            return $this->outcome('Export is not complete.', 404);
        }

        $disk = Storage::disk($job->disk());
        $path = $job->path($type);

        if (! $disk->exists($path)) {
            return $this->outcome("No export file for {$type}.", 404);
        }

        return $disk->download($path, "{$type}.ndjson", [
            'Content-Type' => 'application/fhir+ndjson',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(FhirExportJob $job): array
    {
        return [
            'transactionTime' => $job->transaction_time?->toIso8601String(),
            'request' => url()->current(),
            'requiresAccessToken' => true,
            'output' => $job->output ?? [],
            'error' => [],
        ];
    }

    /**
     * Load the export, or return the response explaining why the caller may not see it.
     *
     * A job belonging to someone else is reported as 404 rather than 403: the
     * existence of another branch's export is itself information.
     */
    private function authorizedJob(string $export): FhirExportJob|JsonResponse
    {
        $job = FhirExportJob::query()->find($export);

        if (! $job) {
            return $this->outcome('Export not found.', 404);
        }

        $readable = $job->isReadableBy(
            (int) Auth::id(),
            CurrentBranch::id(),
        );

        return $readable ? $job : $this->outcome('Export not found.', 404);
    }

    private function outcome(string $message, int $status): JsonResponse
    {
        return $this->responseFactory->operationOutcome([
            [
                'severity' => 'error',
                'code' => $status === 404 ? 'not-found' : 'processing',
                'details' => ['text' => $message],
            ],
        ], $status);
    }
}
