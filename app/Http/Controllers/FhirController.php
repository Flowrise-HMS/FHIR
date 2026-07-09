<?php

namespace Modules\FHIR\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FHIR\Contracts\FhirResourceContract;
use Modules\FHIR\FhirResponse\FhirResponseFactory;
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
    ) {}

    public function read(string $resourceType, string $id): JsonResponse
    {
        $t = $this->resolve($resourceType);
        if (! $t) {
            return $this->responseFactory->notFound($resourceType, $id);
        }
        $model = $t->findById($id);
        if (! $model) {
            return $this->responseFactory->notFound($resourceType, $id);
        }

        return $this->responseFactory->resource($t->toFhir($model));
    }

    public function search(string $resourceType, Request $request): JsonResponse
    {
        $t = $this->resolve($resourceType);
        if (! $t) {
            return $this->responseFactory->operationOutcome([['severity' => 'error', 'code' => 'not-found', 'details' => ['text' => "Unsupported: {$resourceType}"]]], 404);
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
        $fhir = $request->json()->all();
        $v = $this->validator->validate($resourceType, $fhir);
        if (! $v['valid']) {
            return $this->responseFactory->validationError($v['errors']);
        }
        $bv = $t->validateBusinessRules($fhir);
        if (! empty($bv)) {
            return $this->responseFactory->validationError($bv);
        }
        $attrs = $t->fromFhir($fhir);

        return $this->responseFactory->created($fhir, $resourceType, '');
    }

    public function update(string $resourceType, string $id, Request $request): JsonResponse
    {
        $t = $this->resolve($resourceType);
        if (! $t) {
            return $this->responseFactory->notFound($resourceType, $id);
        }
        $model = $t->findById($id);
        if (! $model) {
            return $this->responseFactory->notFound($resourceType, $id);
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
        $attrs = $t->fromFhir($fhir);

        return $this->responseFactory->updated($t->toFhir($model->fresh()));
    }

    public function destroy(string $resourceType, string $id): JsonResponse
    {
        $t = $this->resolve($resourceType);
        if (! $t) {
            return $this->responseFactory->notFound($resourceType, $id);
        }
        $model = $t->findById($id);
        if (! $model) {
            return $this->responseFactory->notFound($resourceType, $id);
        }
        $model->delete();

        return $this->responseFactory->deleted();
    }

    protected function resolve(string $resourceType): ?FhirResourceContract
    {
        $entry = $this->registrar->get($resourceType);

        return $entry ? app($entry['transformer_class']) : null;
    }
}
