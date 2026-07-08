<?php

namespace Modules\FHIR\FhirSearch;

class PaginationHandler
{
    public function generateLinks(string $baseUrl, array $currentParams, int $total, int $count, int $offset): array
    {
        $links = [
            'self' => $this->buildUrl($baseUrl, array_merge($currentParams, ['_count' => $count, '_offset' => $offset])),
        ];

        if ($offset > 0) {
            $links['first'] = $this->buildUrl($baseUrl, array_merge($currentParams, ['_count' => $count, '_offset' => 0]));
        }

        $lastOffset = (int) (ceil($total / $count) - 1) * $count;
        if ($lastOffset < 0) {
            $lastOffset = 0;
        }

        if ($offset + $count < $total) {
            $links['next'] = $this->buildUrl($baseUrl, array_merge($currentParams, ['_count' => $count, '_offset' => $offset + $count]));
        }

        if ($lastOffset > 0 && $lastOffset !== $offset) {
            $links['last'] = $this->buildUrl($baseUrl, array_merge($currentParams, ['_count' => $count, '_offset' => $lastOffset]));
        }

        return $links;
    }

    protected function buildUrl(string $baseUrl, array $params): string
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        return $baseUrl . $separator . http_build_query($params);
    }
}
