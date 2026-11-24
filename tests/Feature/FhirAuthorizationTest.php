<?php

namespace Modules\FHIR\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\EncounterDiagnosis;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\FHIR\FhirRouting\FhirAuthorizer;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\Models\FhirExportJob;
use Modules\Patient\Models\Patient;
use Modules\Staff\Models\Staff;
use Modules\Staff\Models\StaffDepartment;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A valid token is not enough: every FHIR interaction asks the policy behind the
 * resource, the token's `fhir` scope and the caller's branch.
 */
class FhirAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Branch $otherBranch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateModules(['Core', 'Patient', 'Staff', 'Billing', 'Pharmacy', 'Clinical', 'Appointment', 'Diagnostics', 'FHIR']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = Branch::factory()->create();
        $this->otherBranch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    protected function tearDown(): void
    {
        Context::forget('current_branch_id');

        parent::tearDown();
    }

    private function grant(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $abilities
     */
    private function fhir(string $method, string $path, array $payload = [], array $abilities = ['*']): TestResponse
    {
        app('auth')->forgetGuards();
        Context::forget('current_branch_id');

        return $this->withToken($this->user->createToken('t', $abilities)->plainTextToken)
            ->json($method, "/api/v1/fhir{$path}", $payload, ['Accept' => 'application/fhir+json']);
    }

    private function patient(?Branch $branch = null): Patient
    {
        return Patient::withoutEvents(fn (): Patient => Patient::factory()->create(['branch_id' => ($branch ?? $this->branch)->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $family = 'Asante'): array
    {
        return [
            'resourceType' => 'Patient',
            'name' => [['use' => 'official', 'family' => $family, 'given' => ['Akosua']]],
            'gender' => 'female',
            'birthDate' => '1990-01-15',
        ];
    }

    public function test_every_registered_resource_has_a_rule(): void
    {
        $registrar = app(FhirResourceRegistrar::class);
        $missing = [];

        foreach ($registrar->getAll() as $entry) {
            $model = app($entry['transformer_class'])->query()->getModel();

            foreach (array_filter(explode('.', (string) $entry['through'])) as $relation) {
                $model = $model->{$relation}()->getRelated();
            }

            if (Gate::getPolicyFor($model) === null) {
                $missing[] = $entry['resource_type'];
            }
        }

        $this->assertSame([], $missing, 'FHIR resources only super admins could use: '.implode(', ', $missing));
    }

    public function test_a_user_without_permissions_can_do_nothing(): void
    {
        $patient = $this->patient();

        foreach (app(FhirResourceRegistrar::class)->getAll() as $entry) {
            if (in_array('search-type', $entry['interactions'], true)) {
                $this->fhir('GET', "/{$entry['resource_type']}")->assertForbidden();
            }
        }

        $this->fhir('GET', "/Patient/{$patient->id}")->assertForbidden()->assertJsonPath('resourceType', 'OperationOutcome');
        $this->fhir('POST', '/Patient', $this->payload())->assertForbidden();
        $this->fhir('PUT', "/Patient/{$patient->id}", $this->payload())->assertForbidden();
        $this->fhir('DELETE', "/Patient/{$patient->id}")->assertForbidden();
        $this->fhir('GET', '/$export')->assertForbidden();

        $this->assertNotSoftDeleted('patients', ['id' => $patient->id]);
        $this->assertSame(0, FhirExportJob::query()->where('requested_by', $this->user->id)->count());
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function interactions(): array
    {
        return [
            'search' => ['ViewAny Patient', 'GET', '/Patient', '200'],
            'read' => ['View Patient', 'GET', '/Patient/{id}', '200'],
            'create' => ['Create Patient', 'POST', '/Patient', '201'],
            'update' => ['Update Patient', 'PUT', '/Patient/{id}', '200'],
            'delete' => ['Delete Patient', 'DELETE', '/Patient/{id}', '204'],
        ];
    }

    #[DataProvider('interactions')]
    public function test_each_interaction_needs_exactly_its_own_permission(string $permission, string $method, string $path, string $status): void
    {
        $patient = $this->patient();
        $path = str_replace('{id}', $patient->id, $path);
        $payload = in_array($method, ['POST', 'PUT'], true) ? $this->payload() : [];

        $others = array_diff(array_column(self::interactions(), 0), [$permission]);
        $this->grant(...$others);
        $this->fhir($method, $path, $payload)->assertForbidden();

        $this->grant($permission);
        $this->fhir($method, $path, $payload)->assertStatus((int) $status);
    }

    public function test_tokens_need_the_fhir_scope(): void
    {
        $patient = $this->patient();
        $this->grant('View Patient', 'Create Patient');

        $this->fhir('GET', "/Patient/{$patient->id}", abilities: ['clinical:write'])->assertForbidden();
        $this->fhir('GET', "/Patient/{$patient->id}", abilities: ['fhir:read'])->assertOk();
        $this->fhir('POST', '/Patient', $this->payload(), ['fhir:read'])->assertForbidden();
        $this->fhir('POST', '/Patient', $this->payload(), ['fhir:write'])->assertCreated();
    }

    public function test_accounts_without_a_branch_are_refused(): void
    {
        $this->patient();
        $this->grant('ViewAny Patient');
        $this->user->forceFill(['branch_id' => null])->save();

        $this->fhir('GET', '/Patient')->assertForbidden();
    }

    public function test_records_of_another_branch_are_not_found_by_id(): void
    {
        $this->grant('View Patient', 'Update Patient', 'Delete Patient', 'View AllergyIntolerance', 'View Allergy', 'View EncounterDiagnosis', 'View Staff', 'Update Staff');

        $patient = $this->patient($this->otherBranch);
        $allergy = Allergy::factory()->create(['patient_id' => $patient->id]);
        $encounter = Encounter::factory()->create(['patient_id' => $patient->id, 'branch_id' => $this->otherBranch->id]);
        $diagnosis = EncounterDiagnosis::factory()->create(['encounter_id' => $encounter->id, 'patient_id' => $patient->id]);
        $role = $this->practitionerRole($this->otherBranch);

        $this->fhir('GET', "/Patient/{$patient->id}")->assertNotFound();
        $this->fhir('PUT', "/Patient/{$patient->id}", $this->payload())->assertNotFound();
        $this->fhir('DELETE', "/Patient/{$patient->id}")->assertNotFound();
        $this->fhir('GET', "/AllergyIntolerance/{$allergy->id}")->assertNotFound();
        $this->fhir('GET', "/Condition/{$diagnosis->id}")->assertNotFound();
        $this->fhir('GET', "/PractitionerRole/{$role->id}")->assertNotFound();
        $this->fhir('DELETE', "/PractitionerRole/{$role->id}")->assertNotFound();
    }

    public function test_resources_without_a_policy_follow_their_parent(): void
    {
        $role = $this->practitionerRole($this->branch);

        $this->fhir('GET', "/PractitionerRole/{$role->id}")->assertForbidden();
        $this->fhir('DELETE', "/PractitionerRole/{$role->id}")->assertForbidden();

        $this->grant('View Staff', 'ViewAny Staff');
        $this->fhir('GET', "/PractitionerRole/{$role->id}")->assertOk();
        $this->fhir('GET', '/PractitionerRole')->assertOk();
        $this->fhir('DELETE', "/PractitionerRole/{$role->id}")->assertForbidden();

        $this->grant('Update Staff');
        $this->fhir('DELETE', "/PractitionerRole/{$role->id}")->assertNoContent();
    }

    public function test_a_role_cannot_be_created_for_staff_of_another_branch(): void
    {
        $this->grant('Update Staff');
        $department = Department::factory()->create();
        $location = Location::factory()->create(['branch_id' => $this->branch->id]);
        $department->locations()->attach($location->id, ['is_primary' => true]);

        $payload = fn (Staff $staff): array => [
            'resourceType' => 'PractitionerRole',
            'practitioner' => ['reference' => "Practitioner/{$staff->id}"],
            'location' => [['reference' => "Location/{$location->id}"]],
            'code' => [['text' => 'nurse']],
            'period' => ['start' => now()->toDateString()],
        ];

        $foreign = Staff::factory()->create(['branch_id' => $this->otherBranch->id]);
        $this->fhir('POST', '/PractitionerRole', $payload($foreign))->assertUnprocessable();
        $this->assertDatabaseMissing('staff_departments', ['staff_id' => $foreign->id]);
    }

    public function test_bundle_entries_are_authorised_one_by_one(): void
    {
        $existing = $this->patient();
        $bundle = fn (string $type): array => [
            'resourceType' => 'Bundle',
            'type' => $type,
            'entry' => [
                ['request' => ['method' => 'POST', 'url' => 'Patient'], 'resource' => $this->payload('Bundled')],
                ['request' => ['method' => 'PUT', 'url' => "Patient/{$existing->id}"], 'resource' => ['id' => $existing->id, ...$this->payload('Renamed')]],
            ],
        ];

        $this->fhir('POST', '', $bundle('batch'))->assertOk()
            ->assertJsonPath('entry.0.response.status', '403 Forbidden')
            ->assertJsonPath('entry.1.response.status', '403 Forbidden');

        $this->grant('Create Patient');
        $this->fhir('POST', '', $bundle('transaction'))->assertOk()
            ->assertJsonPath('entry.0.response.status', '412 Precondition Failed')
            ->assertJsonPath('entry.1.response.status', '403 Forbidden');
        $this->assertDatabaseMissing('patients', ['last_name' => 'Bundled']);

        $this->fhir('POST', '', $bundle('batch'))->assertJsonPath('entry.0.response.status', '201 Created')
            ->assertJsonPath('entry.1.response.status', '403 Forbidden');
        $this->assertDatabaseMissing('patients', ['last_name' => 'Renamed']);
    }

    public function test_exports_need_the_export_permission_and_cover_only_listable_types(): void
    {
        Queue::fake();
        $latest = fn (): ?FhirExportJob => FhirExportJob::query()->where('requested_by', $this->user->id)->latest('created_at')->latest('id')->first();

        $this->grant('ViewAny Patient');
        $this->fhir('GET', '/$export?_type=Patient')->assertForbidden();

        $this->grant('export_fhir');
        $this->fhir('GET', '/$export?_type=Patient,Encounter')->assertForbidden();
        $this->fhir('GET', '/$export?_type=NoSuchType')->assertStatus(400);
        $this->assertNull($latest());

        $this->fhir('GET', '/$export')->assertStatus(202);
        $this->assertSame(['Patient'], $latest()->types);
    }

    public function test_unknown_interactions_and_anonymous_callers_are_refused(): void
    {
        $registrar = app(FhirResourceRegistrar::class);
        $transformer = app($registrar->get('Patient')['transformer_class']);
        $this->grant('ViewAny Patient');

        $this->assertFalse(app(FhirAuthorizer::class)->allows('vread', $transformer, null, $this->user));
        $this->assertTrue(app(FhirAuthorizer::class)->allows('search-type', $transformer, null, $this->user->fresh()));
        $this->assertFalse(app(FhirAuthorizer::class)->allows('search-type', $transformer));
    }

    private function practitionerRole(Branch $branch): StaffDepartment
    {
        $staff = Staff::factory()->create(['branch_id' => $branch->id]);

        return StaffDepartment::query()->create([
            'staff_id' => $staff->id,
            'department_id' => Department::factory()->create()->id,
            'designation' => 'nurse',
            'start_date' => now()->toDateString(),
        ]);
    }
}
