<?php

namespace Modules\FHIR\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface FhirResourceContract
{
    public function resourceType(): string;

    public function toFhir(Model $model): array;

    public function fromFhir(array $fhirResource): array;

    public function findById(string $id): ?Model;

    public function query(): Builder;

    public function searchableParameters(): array;

    public function validateBusinessRules(array $fhirResource): array;
}
