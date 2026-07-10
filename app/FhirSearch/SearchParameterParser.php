<?php

namespace Modules\FHIR\FhirSearch;

class SearchParameterParser
{
    protected array $datePrefixes = ['eq', 'ne', 'lt', 'gt', 'le', 'ge', 'sa', 'eb'];

    protected array $resourcePrefixes = [
        'Patient/', 'Practitioner/', 'PractitionerRole/',
        'Appointment/', 'AppointmentResponse/',
        'Location/', 'Organization/', 'RelatedPerson/', 'Device/',
    ];

    protected int $maxCount = 100;

    protected int $defaultCount = 20;

    public function parse(array $queryParams): array
    {
        $params = [];
        $params['_count'] = $this->parseCount($queryParams['_count'] ?? null);
        $params['_offset'] = (int) ($queryParams['_offset'] ?? 0);
        $params['_sort'] = $this->parseSort($queryParams['_sort'] ?? null);

        foreach ($queryParams as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            $modifier = null;
            $paramName = $key;

            if (str_contains($key, ':')) {
                [$paramName, $modifier] = explode(':', $key, 2);
            }

            $params['filters'][] = $this->parseValue($paramName, $value, $modifier);
        }

        return $params;
    }

    protected function parseValue(string $name, string $value, ?string $modifier): array
    {
        $prefix = null;
        $searchValue = $value;
        $system = null;

        foreach ($this->datePrefixes as $p) {
            if (str_starts_with($value, $p) && strlen($value) > strlen($p)) {
                $next = substr($value, strlen($p), 1);
                if (ctype_digit($next) || $next === '-') {
                    $prefix = $p;
                    $searchValue = substr($value, strlen($p));
                    break;
                }
            }
        }

        if ($prefix === null && str_contains($value, '|')) {
            $parts = explode('|', $value, 2);
            $system = $parts[0] ?: null;
            $searchValue = $parts[1] ?? '';
        }

        $searchValue = $this->stripReferencePrefix($searchValue);

        return [
            'name' => $name,
            'value' => $searchValue,
            'modifier' => $modifier,
            'prefix' => $prefix,
            'system' => $system,
        ];
    }

    protected function stripReferencePrefix(string $value): string
    {
        foreach ($this->resourcePrefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return substr($value, strlen($prefix));
            }
        }

        return $value;
    }

    protected function parseCount(?string $value): int
    {
        if ($value === null) {
            return $this->defaultCount;
        }

        return min((int) $value, $this->maxCount);
    }

    protected function parseSort(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $sorts = [];
        foreach (explode(',', $value) as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }
            $direction = 'asc';
            if (str_starts_with($field, '-')) {
                $direction = 'desc';
                $field = substr($field, 1);
            }
            $sorts[] = ['field' => $field, 'direction' => $direction];
        }

        return $sorts;
    }
}
