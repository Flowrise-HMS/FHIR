<?php

namespace Modules\FHIR\FhirRouting;

class FhirResourceRegistrar
{
    protected array $resources = [];

    public function register(string $resourceType, string $transformerClass): void
    {
        $this->resources[$resourceType] = [
            'resource_type' => $resourceType,
            'transformer_class' => $transformerClass,
        ];
    }

    public function get(string $resourceType): ?array
    {
        return $this->resources[$resourceType] ?? null;
    }

    public function getAll(): array
    {
        return array_values($this->resources);
    }

    public function has(string $resourceType): bool
    {
        return isset($this->resources[$resourceType]);
    }
}
