<?php

namespace Modules\FHIR\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FHIR\Contracts\FhirResourceContract;
use Modules\FHIR\Contracts\FhirWritableResourceContract;
use Modules\FHIR\FhirResponse\FhirResponseFactory;
use Modules\FHIR\FhirRouting\FhirAuthorizer;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\FhirSearch\SearchParameterParser;
use Modules\FHIR\FhirSearch\SearchQueryBuilder;
use Modules\FHIR\FhirValidation\FhirValidator;

class FhirController extends Controller
{
    public function __construct(
        protected FhirResourceRegistrar $registrar,
        protected FhirResponseFactory $responseFactory,
        protected FhirValidator $validator,
        protected SearchParameterParser $parameterParser,
        protected SearchQueryBuilder $queryBuilder,
        protected FhirAuthorizer $authorizer,
    ) {}

    public function read(string $resourceType, string $id): JsonResponse
    {
        $t = $this->resolve($resourceType);
        if (! $t) {
            return $this->responseFactory->notFound($resourceType, $id);
        }
        $model = $this->authorizer->find($t, $id);
        if (! $model) {
            return $this->responseFactory->notFound($resourceType, $id);
        }

        if (! $this->authorizer->allows('read', $t, $model)) {
            return $this->responseFactory->forbidden();
        }

        return $this->responseFactory->resource($t->toFhir($model));
    }

    public function search(string $resourceType, Request $request): JsonResponse
    {
        $t = $this->resolve($resourceType);
        if (! $t) {
            return $this->responseFactory->operationOutcome([['severity' => 'error', 'code' => 'not-found', 'details' => ['text' => "Unsupported: {$resourceType}"]]], 404);
        }
        if (! $this->authorizer->allows('search-type', $t)) {
            return $this->responseFactory->forbidden();
        }
        $params = $this->parameterParser->parse($request->query());
        $query = $t->query();
        $this->queryBuilder->apply($query, $params, $t->searchableParameters());
        $count = (int) ($params['_count'] ?? 20);
        $offset = (int) ($params['_offset'] ?? 0);
        $total = $query->count();
        $models = $query->skip($offset)->take($count)->get();
        $entries = $models->map(fn ($m) => $t->toFhir($m))->toArray();
        $links = ['self' => $request->fullUrl()];
        if ($offset + $count < $total) {
            $links['next'] = $request->fullUrlWithQuery(['_offset' => $offset + $count, '_count' => $count]);
        }

        return $this->responseFactory->searchSet($entries, $total, $links);
    }

    public function create(string $resourceType, Request $request): JsonResponse
    {
        $t = $this->resolve($resourceType);
        if (! $t) {
            return $this->responseFactory->notFound($resourceType, '');
        }
        if (! $t instanceof FhirWritableResourceContract) {
            return $this->notSupported($resourceType, 'create');
        }
        if (! $this->authorizer->allows('create', $t)) {
            return $this->responseFactory->forbidden();
        }
        $fhir = $request->json()->all();
        $v = $this->validator->validate($resourceType, $fhir);
        if (! $v['valid']) {
            return $this->responseFactory->validationError($v['errors']);
        }
        $bv = $t->validateBusinessRules($fhir);
        if (! empty($bv)) {
            return $this->responseFactory->validationError($bv);
        }

        $model = $t->createFromFhir($fhir);

        return $this->responseFactory->created(
            $t->toFhir($model),
            $resourceType,
            (string) $model->getKey(),
        );
    }

    public function update(string $resourceType, string $id, Request $request): JsonResponse
    {
        $t = $this->resolve($resourceType);
        if (! $t) {
            return $this->responseFactory->notFound($resourceType, $id);
        }
        $model = $this->authorizer->find($t, $id);
        if (! $model) {
            return $this->responseFactory->notFound($resourceType, $id);
        }
        if (! $t instanceof FhirWritableResourceContract) {
            return $this->notSupported($resourceType, 'update');
        }
        if (! $this->authorizer->allows('update', $t, $model)) {
            return $this->responseFactory->forbidden();
        }
        $fhir = array_merge($request->json()->all(), ['id' => $id]);
        $v = $this->validator->validate($resourceType, $fhir);
        if (! $v['valid']) {
            return $this->responseFactory->validationError($v['errors']);
        }
        $bv = $t->validateBusinessRules($fhir);
        if (! empty($bv)) {
            return $this->responseFactory->validationError($bv);
        }

        $updated = $t->updateFromFhir($model, $fhir);

        return $this->responseFactory->updated($t->toFhir($updated));
    }

    public function destroy(string $resourceType, string $id): JsonResponse
    {
        $t = $this->resolve($resourceType);
        if (! $t) {
            return $this->responseFactory->notFound($resourceType, $id);
        }
        $model = $this->authorizer->find($t, $id);
        if (! $model) {
            return $this->responseFactory->notFound($resourceType, $id);
        }
        if (! $this->authorizer->allows('delete', $t, $model)) {
            return $this->responseFactory->forbidden();
        }
        $model->delete();

        return $this->responseFactory->deleted();
    }

    protected function resolve(string $resourceType): ?FhirResourceContract
    {
        $entry = $this->registrar->get($resourceType);

        return $entry ? app($entry['transformer_class']) : null;
    }

    /**
     * A registered resource that cannot service a write.
     *
     * 405 rather than 404: the resource type exists and is readable, but this
     * interaction is not implemented for it.
     */
    protected function notSupported(string $resourceType, string $interaction): JsonResponse
    {
        return $this->responseFactory->operationOutcome([
            [
                'severity' => 'error',
                'code' => 'not-supported',
                'details' => ['text' => "{$resourceType} does not support the {$interaction} interaction"],
            ],
        ], 405);
    }
}
