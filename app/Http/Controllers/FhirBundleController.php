<?php

namespace Modules\FHIR\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Support\CurrentBranch;
use Modules\FHIR\Enums\FhirExportStatus;
use Modules\FHIR\FhirBundle\BulkExporter;
use Modules\FHIR\FhirBundle\BundleImporter;
use Modules\FHIR\FhirResponse\FhirResponseFactory;
use Modules\FHIR\Jobs\GenerateFhirBulkExportJob;
use Modules\FHIR\Models\FhirExportJob;

/**
 * Bundle import and bulk export.
 *
 * These are the two "across the resources" operations: import accepts many
 * resource types in one Bundle, export emits many resource types in one stream.
 * Both delegate per-type work to the registered transformers, so a resource
 * becomes importable or exportable purely by being registered.
 */
class FhirBundleController extends Controller
{
    public function __construct(
        protected BundleImporter $importer,
        protected BulkExporter $exporter,
        protected FhirResponseFactory $responseFactory,
    ) {}

    /**
     * POST /api/v1/fhir — import a transaction or batch Bundle.
     */
    public function import(Request $request): JsonResponse
    {
        $bundle = $request->json()->all();

        if (($bundle['resourceType'] ?? null) !== 'Bundle') {
            return $this->responseFactory->validationError([
                [
                    'severity' => 'error',
                    'code' => 'invalid',
                    'details' => ['text' => 'Request body must be a Bundle.'],
                    'expression' => ['Bundle.resourceType'],
                ],
            ]);
        }

        $type = $bundle['type'] ?? '';

        if (! in_array($type, BundleImporter::SUPPORTED_TYPES, true)) {
            return $this->responseFactory->validationError([
                [
                    'severity' => 'error',
                    'code' => 'not-supported',
                    'details' => ['text' => sprintf(
                        'Bundle.type must be one of: %s. Received: %s',
                        implode(', ', BundleImporter::SUPPORTED_TYPES),
                        $type === '' ? '(none)' : $type,
                    )],
                    'expression' => ['Bundle.type'],
                ],
            ]);
        }

        if (($bundle['entry'] ?? []) === []) {
            return $this->responseFactory->validationError([
                [
                    'severity' => 'error',
                    'code' => 'invalid',
                    'details' => ['text' => 'Bundle contains no entries.'],
                    'expression' => ['Bundle.entry'],
                ],
            ]);
        }

        $response = $this->importer->import($bundle);

        return new JsonResponse($response, 200, ['Content-Type' => 'application/fhir+json']);
    }

    /**
     * GET /api/v1/fhir/$export — kick off an asynchronous bulk export.
     *
     * Per the Bulk Data specification this always answers 202 with a
     * Content-Location to poll; the files are written by a queued job and
     * delivered through FhirExportStatusController. Exports routinely outlive a
     * request, so a synchronous response would either time out or hold a
     * connection open for the duration.
     */
    public function export(Request $request): JsonResponse
    {
        $branchId = CurrentBranch::id();

        if ($branchId === null) {
            return $this->responseFactory->operationOutcome([
                [
                    'severity' => 'error',
                    'code' => 'security',
                    'details' => ['text' => 'No branch assigned to your account.'],
                ],
            ], 403);
        }

        $types = array_values(array_intersect(
            array_values(array_filter(explode(',', (string) $request->query('_type', '')))),
            $this->exporter->exportableTypes(),
        ));

        $since = $request->query('_since');

        $job = FhirExportJob::query()->create([
            'branch_id' => $branchId,
            'requested_by' => $request->user()->getKey(),
            'status' => FhirExportStatus::PENDING,
            'types' => $types === [] ? null : $types,
            'since' => is_string($since) ? $since : null,
        ]);

        GenerateFhirBulkExportJob::dispatch($job->id);

        return new JsonResponse(null, 202, [
            'Content-Location' => route('api.fhir.export.status', ['export' => $job->id]),
        ]);
    }
}
