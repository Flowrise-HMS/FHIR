<?php

namespace Modules\FHIR\FhirResponse;

use Illuminate\Http\JsonResponse;

class FhirResponseFactory
{
    public function resource(array $resource): JsonResponse
    {
        return new JsonResponse($resource, 200, ['Content-Type' => 'application/fhir+json']);
    }

    public function created(array $resource, string $resourceType, string $id): JsonResponse
    {
        return new JsonResponse($resource, 201, [
            'Content-Type' => 'application/fhir+json',
            'Location' => "/fhir/{$resourceType}/{$id}",
            'ETag' => "W/\"{$id}\"",
        ]);
    }

    public function updated(array $resource): JsonResponse
    {
        return new JsonResponse($resource, 200, ['Content-Type' => 'application/fhir+json']);
    }

    public function deleted(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    public function searchSet(array $entries, int $total, array $links = []): JsonResponse
    {
        $bundle = [
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => $total,
            'entry' => array_map(fn ($e) => ['resource' => $e], $entries),
        ];

        if (! empty($links)) {
            $bundle['link'] = [];

            foreach ($links as $relation => $url) {
                $bundle['link'][] = ['relation' => $relation, 'url' => $url];
            }
        }

        return new JsonResponse($bundle, 200, ['Content-Type' => 'application/fhir+json']);
    }

    public function operationOutcome(array $issues, int $statusCode = 422): JsonResponse
    {
        return new JsonResponse([
            'resourceType' => 'OperationOutcome',
            'issue' => $issues,
        ], $statusCode, ['Content-Type' => 'application/fhir+json']);
    }

    public function notFound(string $resourceType, string $id): JsonResponse
    {
        return $this->operationOutcome([
            [
                'severity' => 'error',
                'code' => 'not-found',
                'details' => ['text' => "{$resourceType} with id {$id} not found"],
                'expression' => ["{$resourceType}.id"],
            ],
        ], 404);
    }

    public function unsupportedMediaType(): JsonResponse
    {
        return $this->operationOutcome([
            [
                'severity' => 'error',
                'code' => 'invalid',
                'details' => ['text' => 'Content-Type must be application/fhir+json or application/json'],
            ],
        ], 415);
    }

    public function notAcceptable(): JsonResponse
    {
        return $this->operationOutcome([
            [
                'severity' => 'error',
                'code' => 'invalid',
                'details' => ['text' => 'Accept header must include application/fhir+json'],
            ],
        ], 406);
    }

    public function unauthorized(): JsonResponse
    {
        return $this->operationOutcome([
            [
                'severity' => 'error',
                'code' => 'security',
                'details' => ['text' => 'Authentication required'],
            ],
        ], 401);
    }

    public function forbidden(): JsonResponse
    {
        return $this->operationOutcome([
            [
                'severity' => 'error',
                'code' => 'security',
                'details' => ['text' => 'Insufficient permissions'],
            ],
        ], 403);
    }

    public function validationError(array $issues): JsonResponse
    {
        return $this->operationOutcome($issues, 422);
    }
}
