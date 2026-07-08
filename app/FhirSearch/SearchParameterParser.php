<?php

namespace Modules\FHIR\FhirSearch;

class SearchParameterParser
{
    public function parse(array $queryParams): array
    {
        $params = [];
        foreach ($queryParams as $key => $value) {
            if (str_starts_with($key, '_')) {
                $params[$key] = $value;
                continue;
            }
            $modifier = null;
            $paramName = $key;

            if (str_contains($key, ':')) {
                $parts = explode(':', $key, 2);
                $paramName = $parts[0];
                $modifier = $parts[1];
            }

            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                $prefix = null;
                $val = $v;

                if (preg_match('/^(eq|ne|gt|lt|ge|le|sa|eb|ap|exact|contains|text|above|below|in|not-in|of-type|missing|not)(.+)$/', $v, $m)) {
                    $prefix = $m[1];
                    $val = $m[2];
                }

                $params[$paramName][] = [
                    'value' => $val,
                    'prefix' => $prefix,
                    'modifier' => $modifier,
                ];
            }
        }
        return $params;
    }
}
