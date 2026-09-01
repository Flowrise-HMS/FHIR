<?php

namespace Modules\FHIR\FhirBundle;

use Illuminate\Support\Facades\DB;
use Modules\FHIR\Contracts\FhirWritableResourceContract;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\FhirValidation\FhirValidator;
use Throwable;

/**
 * Imports a FHIR Bundle by dispatching each entry through the same writable
 * transformers the single-resource endpoints use.
 *
 * The two Bundle types differ in exactly one respect, and it is the important one:
 *
 *  - `transaction` is atomic. Any failing entry rolls back every other entry, and
 *    the response reports the failure. This is what a sending system needs when the
 *    entries are related — a patient plus their encounter plus their observations
 *    should not land half-written.
 *  - `batch` entries are independent. A failure is recorded against that entry and
 *    processing continues.
 *
 * Entry order is preserved in the response, because a client matches responses to
 * requests positionally — the FHIR spec has no other correlation for entries
 * without a fullUrl.
 */
class BundleImporter
{
    /** Bundle types this importer accepts. */
    public const SUPPORTED_TYPES = ['transaction', 'batch'];

    public function __construct(
        protected FhirResourceRegistrar $registrar,
        protected FhirValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed> the response Bundle
     */
    public function import(array $bundle): array
    {
        $type = $bundle['type'] ?? '';
        $entries = $bundle['entry'] ?? [];

        if ($type === 'transaction') {
            return $this->importAtomically($entries);
        }

        return $this->buildResponse('batch-response', $this->processEntries($entries));
    }

    /**
     * A transaction must not partially apply.
     *
     * Any entry failure throws, which unwinds the surrounding database
     * transaction; the collected results are then returned with the successful
     * entries downgraded, since none of them actually persisted.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function importAtomically(array $entries): array
    {
        $results = [];

        try {
            DB::transaction(function () use ($entries, &$results): void {
                $results = $this->processEntries($entries);

                foreach ($results as $result) {
                    if (! $result['ok']) {
                        throw new BundleEntryException($result['status'], $result['issues']);
                    }
                }
            });
        } catch (BundleEntryException) {
            $results = $this->markRolledBack($results);
        }

        return $this->buildResponse('transaction-response', $results);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function processEntries(array $entries): array
    {
        $results = [];

        foreach ($entries as $index => $entry) {
            $results[] = $this->processEntry($entry, $index);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function processEntry(array $entry, int $index): array
    {
        $resource = $entry['resource'] ?? [];
        $method = strtoupper($entry['request']['method'] ?? 'POST');
        $resourceType = $resource['resourceType'] ?? null;

        if ($resourceType === null) {
            return $this->failure(400, 'invalid', "Entry {$index} has no resourceType.");
        }

        $registered = $this->registrar->get($resourceType);

        if ($registered === null) {
            return $this->failure(404, 'not-supported', "Unsupported resource type: {$resourceType}.");
        }

        $transformer = app($registered['transformer_class']);

        if (! $transformer instanceof FhirWritableResourceContract) {
            return $this->failure(405, 'not-supported', "{$resourceType} does not support writes.");
        }

        $interaction = $method === 'PUT' ? 'update' : 'create';

        if (! in_array($interaction, $registered['interactions'], true)) {
            return $this->failure(405, 'not-supported', "{$resourceType} does not support the {$interaction} interaction.");
        }

        $validation = $this->validator->validate($resourceType, $resource);

        if (! $validation['valid']) {
            return $this->failure(422, 'invalid', "Entry {$index} failed schema validation.", $validation['errors']);
        }

        $businessErrors = $transformer->validateBusinessRules($resource);

        if ($businessErrors !== []) {
            return $this->failure(422, 'business-rule', "Entry {$index} failed business validation.", $businessErrors);
        }

        try {
            return $method === 'PUT'
                ? $this->applyUpdate($transformer, $resource, $resourceType, $entry, $index)
                : $this->applyCreate($transformer, $resource, $resourceType);
        } catch (BundleEntryException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->failure(500, 'exception', "Entry {$index} could not be written: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private function applyCreate(
        FhirWritableResourceContract $transformer,
        array $resource,
        string $resourceType,
    ): array {
        $model = $transformer->createFromFhir($resource);

        return [
            'ok' => true,
            'status' => '201 Created',
            'location' => "{$resourceType}/{$model->getKey()}",
            'issues' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function applyUpdate(
        FhirWritableResourceContract $transformer,
        array $resource,
        string $resourceType,
        array $entry,
        int $index,
    ): array {
        $id = $resource['id'] ?? $this->idFromRequestUrl($entry['request']['url'] ?? '');

        if ($id === null) {
            return $this->failure(400, 'invalid', "Entry {$index} is a PUT with no resource id.");
        }

        $model = $transformer->findById($id);

        if ($model === null) {
            return $this->failure(404, 'not-found', "{$resourceType}/{$id} not found.");
        }

        $updated = $transformer->updateFromFhir($model, $resource);

        return [
            'ok' => true,
            'status' => '200 OK',
            'location' => "{$resourceType}/{$updated->getKey()}",
            'issues' => [],
        ];
    }

    /**
     * `Patient/123` -> `123`.
     */
    private function idFromRequestUrl(string $url): ?string
    {
        $segments = array_values(array_filter(explode('/', $url)));

        return count($segments) >= 2 ? end($segments) : null;
    }

    /**
     * @param  array<string, mixed>|list<string>  $issues
     * @return array<string, mixed>
     */
    private function failure(int $status, string $code, string $message, array $issues = []): array
    {
        return [
            'ok' => false,
            'status' => $status.' '.$this->reasonPhrase($status),
            'location' => null,
            'issues' => $issues !== [] ? $issues : [$code => $message],
        ];
    }

    /**
     * Entries that succeeded individually but were undone by a sibling's failure.
     *
     * Reporting them as created would be a lie: the rollback means nothing
     * persisted.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function markRolledBack(array $results): array
    {
        return array_map(
            static function (array $result): array {
                if (! $result['ok']) {
                    return $result;
                }

                return [
                    'ok' => false,
                    'status' => '412 Precondition Failed',
                    'location' => null,
                    'issues' => ['transaction' => 'Rolled back: another entry in this transaction failed.'],
                ];
            },
            $results,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function buildResponse(string $type, array $results): array
    {
        return [
            'resourceType' => 'Bundle',
            'type' => $type,
            'entry' => array_map(
                function (array $result): array {
                    $response = ['status' => $result['status']];

                    if ($result['location'] !== null) {
                        $response['location'] = $result['location'];
                    }

                    if ($result['issues'] !== []) {
                        $response['outcome'] = [
                            'resourceType' => 'OperationOutcome',
                            'issue' => $this->toIssues($result['issues']),
                        ];
                    }

                    return ['response' => $response];
                },
                $results,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $issues
     * @return list<array<string, mixed>>
     */
    private function toIssues(array $issues): array
    {
        $result = [];

        foreach ($issues as $key => $detail) {
            $result[] = [
                'severity' => 'error',
                'code' => 'processing',
                'details' => ['text' => is_string($detail) ? $detail : json_encode($detail)],
                'expression' => is_string($key) ? [$key] : [],
            ];
        }

        return $result;
    }

    private function reasonPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            default => 'Error',
        };
    }
}
