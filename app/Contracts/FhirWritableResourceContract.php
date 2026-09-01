<?php

namespace Modules\FHIR\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A FHIR resource that can be written, not merely read.
 *
 * `FhirResourceContract::fromFhir()` is a pure mapper: it turns a FHIR resource
 * into an attribute array and persists nothing. Something has to take that array
 * and write it, and it cannot live in the controller — each resource maps onto a
 * different module's aggregate and must go through that module's service layer,
 * which is where the domain invariants and the audit trail live.
 *
 * This is a separate contract rather than two more methods on
 * FhirResourceContract because most registered resources are read-only. A
 * transformer declaring the `create` or `update` interaction in
 * FhirServiceProvider must implement this; FhirWritableInteractionsTest asserts
 * that the two lists agree, so a resource cannot advertise a write it cannot do.
 */
interface FhirWritableResourceContract extends FhirResourceContract
{
    /**
     * Persist a new record from a validated FHIR resource.
     *
     * @param  array<string, mixed>  $fhirResource
     */
    public function createFromFhir(array $fhirResource): Model;

    /**
     * Apply a validated FHIR resource to an existing record.
     *
     * @param  array<string, mixed>  $fhirResource
     */
    public function updateFromFhir(Model $model, array $fhirResource): Model;
}
