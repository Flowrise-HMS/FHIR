<?php

namespace Modules\FHIR\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Clinical\Enums\CarePlanCategory;
use Modules\Clinical\Enums\CarePlanIntent;
use Modules\Clinical\Enums\CarePlanOrderStatus;
use Modules\Clinical\Enums\CarePlanStatus;
use Modules\Clinical\Enums\GoalAchievementStatus;
use Modules\Clinical\Enums\GoalLifecycleStatus;
use Modules\Clinical\Models\CarePlan;
use Modules\Clinical\Models\CarePlanDiagnosis;
use Modules\Clinical\Models\CarePlanEvaluation;
use Modules\Clinical\Models\CarePlanIntervention;
use Modules\Clinical\Models\CarePlanObjective;
use Modules\Clinical\Models\CarePlanOrder;
use Modules\Clinical\Models\CarePlanProblem;
use Modules\Clinical\Models\CarePlanRoutineCare;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NursingDiagnosisCatalogue;
use Modules\Core\Models\Branch;
use Modules\Patient\Models\Patient;
use Tests\TestCase;

class FhirCarePlanIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private CarePlan $carePlan;

    private CarePlanDiagnosis $diagnosis;

    private CarePlanObjective $objective;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateModules(['Core', 'Patient', 'Clinical', 'FHIR']);

        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($user);

        $patient = Patient::withoutEvents(
            fn (): Patient => Patient::factory()->create(['branch_id' => $branch->id])
        );
        $encounter = Encounter::factory()
            ->forPatient($patient)
            ->active()
            ->create(['branch_id' => $branch->id]);

        $this->carePlan = CarePlan::query()->create([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'encounter_id' => $encounter->id,
            'author_id' => $user->id,
            'custodian_id' => $user->id,
            'category' => CarePlanCategory::NURSING,
            'intent' => CarePlanIntent::PLAN,
            'status' => CarePlanStatus::ACTIVE,
            'period_start' => now()->subDay(),
            'period_end' => now()->addDay(),
            'description' => 'Post-operative nursing care plan.',
        ]);

        CarePlanRoutineCare::query()->create([
            'care_plan_id' => $this->carePlan->id,
            'item' => 'bp',
            'specification' => 'Monitor blood pressure every four hours.',
            'not_applicable' => false,
            'specified_by' => $user->id,
            'specified_at' => now(),
        ]);

        $problem = CarePlanProblem::query()->create([
            'care_plan_id' => $this->carePlan->id,
            'label' => 'Acute pain',
            'status' => 'active',
            'identified_by' => $user->id,
        ]);
        $catalogue = NursingDiagnosisCatalogue::factory()->create();
        $this->diagnosis = CarePlanDiagnosis::query()->create([
            'care_plan_id' => $this->carePlan->id,
            'care_plan_problem_id' => $problem->id,
            'catalogue_id' => $catalogue->id,
            'problem_statement' => 'Acute pain',
            'related_to' => 'surgical incision',
            'as_evidenced_by' => 'pain score of 8',
            'composed_statement' => 'Acute pain related to surgical incision as evidenced by pain score of 8',
            'recorded_at' => now(),
            'formulated_by' => $user->id,
        ]);
        $order = CarePlanOrder::query()->create([
            'care_plan_diagnosis_id' => $this->diagnosis->id,
            'sequence' => 1,
            'instruction' => 'Administer prescribed analgesia.',
            'frequency' => 'every 4 hours',
            'status' => CarePlanOrderStatus::IN_PROGRESS,
        ]);
        CarePlanIntervention::query()->create([
            'care_plan_order_id' => $order->id,
            'description' => 'Analgesia administered.',
            'performed_at' => now(),
            'performed_by' => $user->id,
        ]);

        $this->objective = CarePlanObjective::query()->create([
            'care_plan_diagnosis_id' => $this->diagnosis->id,
            'description' => 'Patient reports pain score below 3.',
            'target_measure' => 'pain score',
            'target_value' => '< 3',
            'lifecycle_status' => GoalLifecycleStatus::ACTIVE,
            'achievement_status' => GoalAchievementStatus::IMPROVING,
            'author_id' => $user->id,
        ]);
        CarePlanEvaluation::query()->create([
            'care_plan_objective_id' => $this->objective->id,
            'evaluated_by' => $user->id,
            'evaluated_at' => now(),
            'outcome' => 'partially_met',
            'findings' => 'Pain score reduced to 4.',
            'next_action' => 'continue',
            'achievement_status_snapshot' => GoalAchievementStatus::IMPROVING,
        ]);
    }

    public function test_can_read_care_plan_with_its_fhir_relationships(): void
    {
        $response = $this->getJson("/api/v1/fhir/CarePlan/{$this->carePlan->id}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/fhir+json')
            ->assertJsonPath('resourceType', 'CarePlan')
            ->assertJsonPath('id', $this->carePlan->id)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('intent', 'plan')
            ->assertJsonPath('subject.reference', "Patient/{$this->carePlan->patient_id}")
            ->assertJsonPath('encounter.reference', "Encounter/{$this->carePlan->encounter_id}")
            ->assertJsonPath('goal.0.reference', "Goal/{$this->objective->id}")
            ->assertJsonPath('activity.0.detail.description', 'Administer prescribed analgesia.')
            ->assertJsonPath('note.0.text', 'Post-operative nursing care plan.');
    }

    public function test_can_search_care_plans(): void
    {
        $response = $this->getJson("/api/v1/fhir/CarePlan?patient={$this->carePlan->patient_id}&status=active");

        $response->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('type', 'searchset')
            ->assertJsonPath('entry.0.resource.resourceType', 'CarePlan')
            ->assertJsonPath('entry.0.resource.id', $this->carePlan->id);
    }

    public function test_can_read_goal_with_evaluation_progress(): void
    {
        $response = $this->getJson("/api/v1/fhir/Goal/{$this->objective->id}");

        $response->assertOk()
            ->assertJsonPath('resourceType', 'Goal')
            ->assertJsonPath('id', $this->objective->id)
            ->assertJsonPath('lifecycleStatus', 'active')
            ->assertJsonPath('achievementStatus.coding.0.code', 'improving')
            ->assertJsonPath('description.text', 'Patient reports pain score below 3.')
            ->assertJsonPath('subject.reference', "Patient/{$this->carePlan->patient_id}")
            ->assertJsonPath('addresses.0.reference', "Condition/{$this->diagnosis->id}")
            ->assertJsonPath('note.0.text', 'Pain score reduced to 4.');
    }

    public function test_can_search_goals(): void
    {
        $response = $this->getJson("/api/v1/fhir/Goal?patient={$this->carePlan->patient_id}&lifecycle-status=active");

        $response->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('type', 'searchset')
            ->assertJsonPath('entry.0.resource.resourceType', 'Goal')
            ->assertJsonPath('entry.0.resource.id', $this->objective->id);
    }

    public function test_metadata_lists_care_plan_and_goal_as_read_only_resources(): void
    {
        $response = $this->getJson('/api/v1/fhir/metadata');

        $response->assertOk();
        $resources = collect($response->json('rest.0.resource'));

        foreach (['CarePlan', 'Goal'] as $resourceType) {
            $resource = $resources->firstWhere('type', $resourceType);

            $this->assertNotNull($resource);
            $this->assertSame(
                ['read', 'search-type'],
                collect($resource['interaction'])->pluck('code')->all(),
            );
        }
    }
}
