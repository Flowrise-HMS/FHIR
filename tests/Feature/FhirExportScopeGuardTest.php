<?php

namespace Modules\FHIR\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\FHIR\Contracts\FhirResourceContract;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Tests\TestCase;

/**
 * Every FHIR resource reachable by read or bulk export must be branch-isolated.
 *
 * BulkExporter inherits scoping from each transformer's `query()` and adds none of
 * its own, so an unscoped transformer turns a per-record leak into a whole-database
 * disclosure the moment `$export` runs without a `_type` filter.
 *
 * Detection is deliberately by *runtime global scope*, not by class hierarchy:
 * 15 models in this codebase extend BaseModel and then override
 * `bootBelongsToBranch()` to an empty method, so `is_subclass_of($m, BaseModel::class)`
 * reports them as scoped when they are not. Allergy, EncounterDiagnosis and
 * CarePlanObjective all leaked for exactly that reason.
 */
class FhirExportScopeGuardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Resource types with no branch dimension, each with the reason it is safe.
     *
     * Adding an entry here is a deliberate, reviewable statement that the resource
     * carries no patient-identifying data. It is not a place to silence a failure.
     *
     * @var array<string, string>
     */
    private const UNSCOPED_BY_DESIGN = [
        'Organization' => 'The tenant root itself — Organization is what branches belong to.',
        'Location' => 'Facility topology. Rows may legitimately have a null branch (shared locations).',
        'HealthcareService' => 'Department catalogue; reaches a branch only through the department_location pivot.',
        'InventoryItem' => 'Facility-wide item catalogue, not patient data.',
        'PractitionerRole' => 'StaffDepartment is a Pivot with no branch column; staff roster, not clinical data.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Patient', 'Clinical', 'FHIR']);
    }

    public function test_every_exportable_resource_is_branch_scoped_or_explicitly_allow_listed(): void
    {
        $registrar = app(FhirResourceRegistrar::class);
        $offenders = [];

        foreach ($registrar->getAll() as $entry) {
            $resourceType = $entry['resource_type'];

            if (array_key_exists($resourceType, self::UNSCOPED_BY_DESIGN)) {
                continue;
            }

            /** @var FhirResourceContract $transformer */
            $transformer = app($entry['transformer_class']);

            if (! $this->isBranchIsolated($transformer)) {
                $offenders[] = sprintf(
                    '%s (%s) is neither branch-scoped nor allow-listed.',
                    $resourceType,
                    $entry['transformer_class'],
                );
            }
        }

        $this->assertSame([], $offenders, implode(PHP_EOL, $offenders));
    }

    public function test_the_allow_list_only_contains_registered_resources(): void
    {
        $registered = array_column(app(FhirResourceRegistrar::class)->getAll(), 'resource_type');

        $stale = array_values(array_diff(array_keys(self::UNSCOPED_BY_DESIGN), $registered));

        $this->assertSame(
            [],
            $stale,
            'Allow-list names resources that are no longer registered: '.implode(', ', $stale),
        );
    }

    /**
     * A transformer is branch-isolated if its model carries the `branch` global
     * scope, or its query constrains through a relation that does.
     */
    private function isBranchIsolated(FhirResourceContract $transformer): bool
    {
        $query = $transformer->query();
        $model = $query->getModel();

        if ($this->hasBranchScope($model)) {
            return true;
        }

        // Otherwise the query itself must carry a whereHas constraint — that is how
        // a model with no branch column inherits isolation from a scoped parent.
        return $this->constrainsThroughRelation($query->toSql());
    }

    private function hasBranchScope(Model $model): bool
    {
        return array_key_exists('branch', $model->getGlobalScopes());
    }

    private function constrainsThroughRelation(string $sql): bool
    {
        return str_contains($sql, 'exists (select');
    }
}
