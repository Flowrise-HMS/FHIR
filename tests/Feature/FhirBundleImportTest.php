<?php

namespace Modules\FHIR\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Context;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Organization;
use Modules\Patient\Models\Patient;
use Tests\TestCase;

/**
 * Bundle import.
 *
 * The atomicity tests matter most: a transaction Bundle that half-applies is worse
 * than one that fails outright, because the sender believes the whole Bundle landed.
 */
class FhirBundleImportTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Patient', 'FHIR']);

        $organization = Organization::factory()->create([
            'name' => 'Test Org',
            'display_name' => 'Test Org',
            'is_active' => true,
        ]);

        $branch = Branch::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Branch A',
            'display_name' => 'Branch A',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create(['branch_id' => $branch->id]);

        Context::add('current_branch_id', $branch->id);
    }

    protected function tearDown(): void
    {
        Context::forget('current_branch_id');

        parent::tearDown();
    }

    private function patientEntry(string $family, string $given = 'Ama'): array
    {
        return [
            'request' => ['method' => 'POST', 'url' => 'Patient'],
            'resource' => [
                'resourceType' => 'Patient',
                'name' => [['use' => 'official', 'family' => $family, 'given' => [$given]]],
                'gender' => 'female',
                'birthDate' => '1990-01-15',
            ],
        ];
    }

    private function postBundle(array $bundle)
    {
        return $this->actingAs($this->user)
            ->withHeaders([
                'Content-Type' => 'application/fhir+json',
                'Accept' => 'application/fhir+json',
            ])
            ->postJson('/api/v1/fhir', $bundle);
    }

    public function test_a_batch_bundle_creates_every_entry(): void
    {
        $response = $this->postBundle([
            'resourceType' => 'Bundle',
            'type' => 'batch',
            'entry' => [$this->patientEntry('Asante'), $this->patientEntry('Boateng')],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('resourceType', 'Bundle');
        $response->assertJsonPath('type', 'batch-response');
        $response->assertJsonPath('entry.0.response.status', '201 Created');
        $response->assertJsonPath('entry.1.response.status', '201 Created');

        $this->assertDatabaseHas('patients', ['last_name' => 'Asante']);
        $this->assertDatabaseHas('patients', ['last_name' => 'Boateng']);
    }

    public function test_a_transaction_bundle_creates_every_entry(): void
    {
        $this->postBundle([
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => [$this->patientEntry('Osei'), $this->patientEntry('Adjei')],
        ])->assertStatus(200)->assertJsonPath('type', 'transaction-response');

        $this->assertDatabaseHas('patients', ['last_name' => 'Osei']);
        $this->assertDatabaseHas('patients', ['last_name' => 'Adjei']);
    }

    public function test_a_failing_entry_rolls_back_the_whole_transaction(): void
    {
        $response = $this->postBundle([
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => [
                $this->patientEntry('Nkrumah'),
                [
                    'request' => ['method' => 'POST', 'url' => 'CarePlan'],
                    'resource' => ['resourceType' => 'CarePlan', 'status' => 'active'],
                ],
            ],
        ]);

        $response->assertStatus(200);

        // The sibling that "succeeded" must not be reported as created, because
        // the rollback means it did not persist.
        $response->assertJsonPath('entry.0.response.status', '412 Precondition Failed');
        $response->assertJsonPath('entry.1.response.status', '405 Method Not Allowed');

        $this->assertDatabaseMissing('patients', ['last_name' => 'Nkrumah']);
    }

    public function test_a_failing_entry_in_a_batch_does_not_affect_siblings(): void
    {
        $response = $this->postBundle([
            'resourceType' => 'Bundle',
            'type' => 'batch',
            'entry' => [
                $this->patientEntry('Danquah'),
                [
                    'request' => ['method' => 'POST', 'url' => 'CarePlan'],
                    'resource' => ['resourceType' => 'CarePlan', 'status' => 'active'],
                ],
            ],
        ]);

        $response->assertJsonPath('entry.0.response.status', '201 Created');
        $response->assertJsonPath('entry.1.response.status', '405 Method Not Allowed');

        $this->assertDatabaseHas('patients', ['last_name' => 'Danquah']);
    }

    public function test_a_put_entry_updates_an_existing_record(): void
    {
        $patient = Patient::factory()->create(['last_name' => 'Original']);

        $this->postBundle([
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => [[
                'request' => ['method' => 'PUT', 'url' => "Patient/{$patient->id}"],
                'resource' => [
                    'resourceType' => 'Patient',
                    'id' => $patient->id,
                    'name' => [['use' => 'official', 'family' => 'Revised', 'given' => ['Ama']]],
                    'gender' => 'female',
                ],
            ]],
        ])->assertJsonPath('entry.0.response.status', '200 OK');

        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'last_name' => 'Revised']);
    }

    public function test_an_unknown_resource_type_is_reported_per_entry(): void
    {
        $this->postBundle([
            'resourceType' => 'Bundle',
            'type' => 'batch',
            'entry' => [[
                'request' => ['method' => 'POST', 'url' => 'Unicorn'],
                'resource' => ['resourceType' => 'Unicorn'],
            ]],
        ])->assertJsonPath('entry.0.response.status', '404 Not Found');
    }

    public function test_a_non_bundle_body_is_rejected(): void
    {
        $this->postBundle(['resourceType' => 'Patient'])->assertStatus(422);
    }

    public function test_an_unsupported_bundle_type_is_rejected(): void
    {
        $this->postBundle([
            'resourceType' => 'Bundle',
            'type' => 'document',
            'entry' => [$this->patientEntry('Ignored')],
        ])->assertStatus(422);
    }

    public function test_an_empty_bundle_is_rejected(): void
    {
        $this->postBundle([
            'resourceType' => 'Bundle',
            'type' => 'batch',
            'entry' => [],
        ])->assertStatus(422);
    }

    public function test_import_requires_authentication(): void
    {
        $this->postJson('/api/v1/fhir', [
            'resourceType' => 'Bundle',
            'type' => 'batch',
            'entry' => [$this->patientEntry('Anon')],
        ])->assertStatus(401);
    }
}
