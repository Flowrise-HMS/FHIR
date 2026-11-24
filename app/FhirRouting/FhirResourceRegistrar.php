<?php

namespace Modules\FHIR\FhirRouting;

class FhirResourceRegistrar
{
    protected array $resources = [];

    /**
     * @param  list<string>  $interactions
     * @param  string|null  $through  relation path to the record whose policy governs this
     *                                resource, when its model has none of its own (see FhirAuthorizer)
     */
    public function register(string $resourceType, string $transformerClass, array $interactions = ['read', 'search-type', 'create', 'update', 'delete'], ?string $through = null): void
    {
        $this->resources[$resourceType] = [
            'resource_type' => $resourceType,
            'transformer_class' => $transformerClass,
            'interactions' => $interactions,
            'through' => $through,
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

    public function getInteractions(string $resourceType): array
    {
        return $this->resources[$resourceType]['interactions'] ?? ['read', 'search-type'];
    }
}
