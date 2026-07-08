<?php

namespace Modules\FHIR\FhirSearch;

use Illuminate\Database\Eloquent\Builder;

class SearchQueryBuilder
{
    public function apply(Builder $query, array $params, array $searchableParams): void
    {
        foreach ($params as $paramName => $paramValues) {
            if (str_starts_with($paramName, '_')) {
                continue;
            }
            if (!isset($searchableParams[$paramName])) {
                continue;
            }
            $column = $searchableParams[$paramName];

            foreach ($paramValues as $pv) {
                $value = $pv['value'];
                $prefix = $pv['prefix'] ?? 'eq';

                switch ($prefix) {
                    case 'eq':
                        $query->where($column, '=', $value);
                        break;
                    case 'ne':
                        $query->where($column, '!=', $value);
                        break;
                    case 'gt':
                        $query->where($column, '>', $value);
                        break;
                    case 'lt':
                        $query->where($column, '<', $value);
                        break;
                    case 'ge':
                        $query->where($column, '>=', $value);
                        break;
                    case 'le':
                        $query->where($column, '<=', $value);
                        break;
                    case 'contains':
                        $query->where($column, 'like', "%{$value}%");
                        break;
                    case 'exact':
                        $query->where($column, '=', $value);
                        break;
                    default:
                        $query->where($column, '=', $value);
                        break;
                }
            }
        }
    }
}
