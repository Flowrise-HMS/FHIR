<?php

namespace Modules\FHIR\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Appointment\Models\Appointment;
use Modules\Appointment\Models\AppointmentParticipant;
use Modules\Core\Models\Branch;
use Modules\Patient\Models\Patient;
use Modules\Staff\Models\Staff;
use Tests\TestCase;

class FhirAppointmentIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Patient $patient;

    private Staff $practitioner;

    private Appointment $appointment;

    private AppointmentParticipant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateModules(['Core', 'Patient', 'Staff', 'Appointment', 'FHIR']);

        $this->branch = Branch::factory()->create();
        $this->patient = Patient::factory()->create();
        $this->practitioner = Staff::factory()->create();

        $this->appointment = Appointment::factory()->create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'practitioner_primary_id' => $this->practitioner->id,
            'status' => 'booked',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHours(1),
            'priority' => 5,
        ]);

        $this->participant = AppointmentParticipant::factory()->create([
            'appointment_id' => $this->appointment->id,
            'branch_id' => $this->branch->id,
            'participant_type_code' => 'PPRF',
            'actor_reference' => "Practitioner/{$this->practitioner->id}",
            'required' => true,
            'status' => 'accepted',
        ]);
    }

    public function test_can_read_appointment(): void
    {
        $response = $this->getJson("/api/v1/fhir/Appointment/{$this->appointment->id}");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/fhir+json');
        $response->assertJson([
            'resourceType' => 'Appointment',
            'id' => $this->appointment->id,
            'status' => 'booked',
        ]);
        $response->assertJsonStructure([
            'resourceType', 'id', 'status', 'priority', 'start', 'end',
            'participant', 'subject', 'created',
        ]);
    }

    public function test_can_search_appointments(): void
    {
        $response = $this->getJson('/api/v1/fhir/Appointment?status=booked');

        $response->assertStatus(200);
        $response->assertJson([
            'resourceType' => 'Bundle',
            'type' => 'searchset',
        ]);
        $response->assertJsonStructure([
            'resourceType', 'type', 'total', 'entry' => [
                ['resource' => ['resourceType' => 'Appointment']],
            ],
        ]);
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
    }

    public function test_can_read_appointment_response(): void
    {
        $response = $this->getJson("/api/v1/fhir/AppointmentResponse/{$this->participant->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'resourceType' => 'AppointmentResponse',
            'id' => $this->participant->id,
            'participantStatus' => 'accepted',
        ]);
    }

    public function test_metadata_includes_new_resources(): void
    {
        $response = $this->getJson('/api/v1/fhir/metadata');

        $response->assertStatus(200);
        $resourceTypes = collect($response->json('rest.0.resource'))->pluck('type')->all();

        $this->assertContains('Appointment', $resourceTypes);
        $this->assertContains('AppointmentResponse', $resourceTypes);
        $this->assertContains('Patient', $resourceTypes);
    }

    public function test_appointment_has_read_only_interactions(): void
    {
        $response = $this->getJson('/api/v1/fhir/metadata');

        $appointment = collect($response->json('rest.0.resource'))
            ->firstWhere('type', 'Appointment');

        $codes = collect($appointment['interaction'])->pluck('code')->all();
        $this->assertContains('read', $codes);
        $this->assertContains('search-type', $codes);
        $this->assertNotContains('create', $codes);
        $this->assertNotContains('update', $codes);
        $this->assertNotContains('delete', $codes);
    }

    public function test_patient_read_still_works_with_r4_schema(): void
    {
        $response = $this->getJson("/api/v1/fhir/Patient/{$this->patient->id}");

        $response->assertStatus(200);
        $response->assertJson(['resourceType' => 'Patient']);
    }
}
