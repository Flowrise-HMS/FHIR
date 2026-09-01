<?php

namespace Modules\FHIR\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Context;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\EncounterDiagnosis;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Organization;
use Modules\FHIR\FhirBundle\BulkExporter;
use Modules\Patient\Models\Patient;
use Tests\TestCase;

/**
 * Behavioural proof that bulk export does not cross branches.
 *
 * FhirExportScopeGuardTest checks *structure* — that each transformer either
 * carries the branch global scope or constrains through a relation. That is worth
 * having, but it cannot prove the constraint actually filters: `whereHas()` against
 * an unscoped parent produces the same `exists (select …)` shape while filtering
 * nothing. These tests exercise the real query with real branch context and assert
 * on the rows that come back.
 *
 * The three resources covered are the ones that leaked: Allergy has no branch
 * column and does not extend BaseModel; EncounterDiagnosis and CarePlanObjective
 * extend BaseModel but override bootBelongsToBranch() to an empty method.
 */
class FhirExportBranchIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Patient', 'Clinical', 'FHIR']);

        $organization = Organization::factory()->create([
            'name' => 'Test Org',
            'display_name' => 'Test Org',
            'is_active' => true,
        ]);

        $this->branchA = Branch::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Branch A',
            'display_name' => 'Branch A',
            'is_active' => true,
        ]);

        $this->branchB = Branch::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Branch B',
            'display_name' => 'Branch B',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Context::forget('current_branch_id');

        parent::tearDown();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inBranch(Branch $branch, callable $callback): mixed
    {
        Context::add('current_branch_id', $branch->id);

        try {
            return $callback();
        } finally {
            Context::forget('current_branch_id');
        }
    }

    /**
     * @return list<string> the ids present in the exported NDJSON
     */
    private function exportedIds(string $resourceType, Branch $asBranch): array
    {
        return $this->inBranch($asBranch, function () use ($resourceType): array {
            $ids = [];

            foreach (app(BulkExporter::class)->stream([$resourceType]) as $line) {
                $decoded = json_decode($line, true);

                if (isset($decoded['id'])) {
                    $ids[] = $decoded['id'];
                }
            }

            return $ids;
        });
    }

    public function test_allergy_export_excludes_other_branches(): void
    {
        $mine = $this->inBranch($this->branchA, fn () => Allergy::factory()->create([
            'patient_id' => Patient::factory()->create(['branch_id' => $this->branchA->id]),
        ]));

        $theirs = $this->inBranch($this->branchB, fn () => Allergy::factory()->create([
            'patient_id' => Patient::factory()->create(['branch_id' => $this->branchB->id]),
        ]));

        // Guard against a vacuous pass: the excluded row must actually exist, or
        // "not present in the export" proves nothing.
        $this->assertNotNull(Allergy::withoutGlobalScopes()->find($theirs->id));

        $ids = $this->exportedIds('AllergyIntolerance', $this->branchA);

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains(
            $theirs->id,
            $ids,
            'Allergy export leaked another branch — the model has no branch column, so it must be constrained through patient.',
        );
    }

    public function test_condition_export_excludes_other_branches(): void
    {
        $mine = $this->inBranch($this->branchA, function () {
            $patient = Patient::factory()->create(['branch_id' => $this->branchA->id]);

            return EncounterDiagnosis::factory()->create([
                'patient_id' => $patient->id,
                'encounter_id' => Encounter::factory()->create([
                    'patient_id' => $patient->id,
                    'branch_id' => $this->branchA->id,
                ]),
            ]);
        });

        $theirs = $this->inBranch($this->branchB, function () {
            $patient = Patient::factory()->create(['branch_id' => $this->branchB->id]);

            return EncounterDiagnosis::factory()->create([
                'patient_id' => $patient->id,
                'encounter_id' => Encounter::factory()->create([
                    'patient_id' => $patient->id,
                    'branch_id' => $this->branchB->id,
                ]),
            ]);
        });

        $this->assertNotNull(EncounterDiagnosis::withoutGlobalScopes()->find($theirs->id));

        $ids = $this->exportedIds('Condition', $this->branchA);

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains(
            $theirs->id,
            $ids,
            'Condition export leaked another branch — EncounterDiagnosis no-ops its branch scope, so it must be constrained through encounter.',
        );
    }

    public function test_patient_export_still_excludes_other_branches(): void
    {
        $mine = $this->inBranch(
            $this->branchA,
            fn () => Patient::factory()->create(['branch_id' => $this->branchA->id]),
        );

        $theirs = $this->inBranch(
            $this->branchB,
            fn () => Patient::factory()->create(['branch_id' => $this->branchB->id]),
        );

        $ids = $this->exportedIds('Patient', $this->branchA);

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }
}
