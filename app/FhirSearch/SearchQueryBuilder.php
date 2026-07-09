<?php

namespace Modules\FHIR\FhirSearch;

use Illuminate\Database\Eloquent\Builder;

class SearchQueryBuilder
{
    public function apply(Builder $query, array $params, array $searchableParams): void
    {
        foreach ($params['filters'] ?? [] as $filter) {
            $name = $filter['name'];

            if (! isset($searchableParams[$name])) {
                continue;
            }

            $config = $searchableParams[$name];

            if (isset($config['relation'])) {
                $this->applyRelationSearch($query, $filter, $config);

                continue;
            }

            $this->applyColumnSearch($query, $filter, $config);
        }

        foreach ($params['_sort'] ?? [] as $sort) {
            $field = $sort['field'];
            if (isset($searchableParams[$field])) {
                $query->orderBy($searchableParams[$field]['column'] ?? $field, $sort['direction']);
            }
        }
    }

    protected function applyColumnSearch(Builder $query, array $filter, array $config): void
    {
        $column = $config['column'] ?? $filter['name'];
        $value = $filter['value'];
        $modifier = $filter['modifier'];
        $prefix = $filter['prefix'];

        if ($prefix !== null) {
            $this->applyDateSearch($query, $column, $prefix, $value);

            return;
        }

        if ($modifier === 'exact') {
            $query->where($column, '=', $value);

            return;
        }

        if ($modifier === 'contains') {
            $query->where($column, 'LIKE', '%'.$value.'%');

            return;
        }

        $query->where($column, 'LIKE', $value.'%');
    }

    protected function applyDateSearch(Builder $query, string $column, string $prefix, string $value): void
    {
        $operatorMap = [
            'eq' => '=', 'ne' => '!=', 'lt' => '<', 'gt' => '>',
            'le' => '<=', 'ge' => '>=', 'sa' => '>', 'eb' => '<',
        ];

        $operator = $operatorMap[$prefix] ?? '=';
        $query->where($column, $operator, $value);
    }

    protected function applyRelationSearch(Builder $query, array $filter, array $config): void
    {
        $value = $filter['value'];
        $modifier = $filter['modifier'];

        $query->whereHas($config['relation'], function ($q) use ($config, $value, $modifier) {
            $column = $config['column'] ?? 'value';
            if ($modifier === 'exact') {
                $q->where($column, '=', $value);
            } else {
                $q->where($column, 'LIKE', $value.'%');
            }
        });
    }
}
