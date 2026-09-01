<?php

namespace Modules\FHIR\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Context;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Organization;
use Modules\FHIR\FhirBundle\BulkExporter;
use Modules\Patient\Models\Patient;
use Tests\TestCase;

/**
 * The NDJSON generator behind bulk export.
 *
 * These assertions used to run against `GET /$export` when that endpoint streamed
 * synchronously. It now answers 202 and hands the work to a queued job, so the
 * HTTP contract is covered by FhirAsyncExportTest and what remains here is the
 * generator itself — the piece both the API and the Filament action depend on.
 */
class FhirBulkExportTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Patient', 'FHIR']);

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

    private function patientIn(Branch $branch, string $family): Patient
    {
        Context::add('current_branch_id', $branch->id);
        $patient = Patient::factory()->create(['branch_id' => $branch->id, 'last_name' => $family]);
        Context::forget('current_branch_id');

        return $patient;
    }

    /**
     * @param  list<string>  $types
     */
    private function export(array $types, Branch $asBranch): string
    {
        Context::add('current_branch_id', $asBranch->id);

        try {
            $out = '';

            foreach (app(BulkExporter::class)->stream($types) as $line) {
                $out .= $line;
            }

            return $out;
        } finally {
            Context::forget('current_branch_id');
        }
    }

    public function test_each_line_is_a_complete_fhir_resource(): void
    {
        $this->patientIn($this->branchA, 'Asante');
        $this->patientIn($this->branchA, 'Boateng');

        $lines = array_values(array_filter(explode("\n", $this->export(['Patient'], $this->branchA))));

        $this->assertNotEmpty($lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            $this->assertIsArray($decoded, 'Each NDJSON line must decode independently.');
            $this->assertSame('Patient', $decoded['resourceType']);
            $this->assertArrayHasKey('id', $decoded);
        }
    }

    public function test_export_is_scoped_to_the_callers_branch(): void
    {
        $mine = $this->patientIn($this->branchA, 'Mine');
        $theirs = $this->patientIn($this->branchB, 'Theirs');

        $content = $this->export(['Patient'], $this->branchA);

        $this->assertStringContainsString($mine->id, $content);
        $this->assertStringNotContainsString(
            $theirs->id,
            $content,
            'Bulk export leaked a record from another branch.',
        );
    }

    public function test_type_filter_restricts_the_output(): void
    {
        $this->patientIn($this->branchA, 'Asante');

        $lines = array_values(array_filter(explode("\n", $this->export(['Patient'], $this->branchA))));

        foreach ($lines as $line) {
            $this->assertSame('Patient', json_decode($line, true)['resourceType']);
        }
    }

    public function test_an_unknown_type_yields_no_rows_rather_than_everything(): void
    {
        $this->patientIn($this->branchA, 'Asante');

        $this->assertSame('', trim($this->export(['Unicorn'], $this->branchA)));
    }

    public function test_exportable_types_are_the_readable_registered_types(): void
    {
        $types = app(BulkExporter::class)->exportableTypes();

        $this->assertContains('Patient', $types);
        $this->assertNotContains('Unicorn', $types);
    }
}
