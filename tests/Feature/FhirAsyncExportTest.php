<?php

namespace Modules\FHIR\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Organization;
use Modules\FHIR\Enums\FhirExportStatus;
use Modules\FHIR\FhirBundle\BulkExporter;
use Modules\FHIR\Jobs\GenerateFhirBulkExportJob;
use Modules\FHIR\Models\FhirExportJob;
use Modules\Patient\Models\Patient;
use Tests\TestCase;

/**
 * Asynchronous bulk export: request, run, poll, download, cancel.
 *
 * The test that matters most is
 * test_the_worker_exports_only_the_requesting_branch. A queued job inherits no
 * request and no authenticated user, so if it fails to re-establish branch context
 * from its own row, BelongsToBranch finds a null branch id, applies no filter, and
 * the export silently succeeds containing every branch's data.
 */
class FhirAsyncExportTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

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

        $this->user = User::factory()->create(['branch_id' => $this->branchA->id]);

        Storage::fake('local');
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

    private function requestExport(string $query = '?_type=Patient')
    {
        return $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'application/fhir+json', 'Prefer' => 'respond-async'])
            ->getJson('/api/v1/fhir/$export'.$query);
    }

    public function test_export_request_returns_202_with_a_status_location(): void
    {
        Queue::fake();

        $response = $this->requestExport();

        $response->assertStatus(202);
        $this->assertNotEmpty($response->headers->get('Content-Location'));

        Queue::assertPushed(GenerateFhirBulkExportJob::class);
    }

    public function test_the_export_row_records_the_requester_and_branch(): void
    {
        Queue::fake();

        $this->requestExport();

        $job = FhirExportJob::query()->latest('created_at')->firstOrFail();

        $this->assertSame($this->branchA->id, $job->branch_id);
        $this->assertSame($this->user->id, $job->requested_by);
        $this->assertSame(['Patient'], $job->types);
    }

    public function test_the_worker_exports_only_the_requesting_branch(): void
    {
        $mine = $this->patientIn($this->branchA, 'Mine');
        $theirs = $this->patientIn($this->branchB, 'Theirs');

        $job = FhirExportJob::query()->create([
            'branch_id' => $this->branchA->id,
            'requested_by' => $this->user->id,
            'status' => FhirExportStatus::PENDING,
            'types' => ['Patient'],
        ]);

        // No Context and no authenticated user — exactly the worker's situation.
        Context::forget('current_branch_id');
        auth()->logout();

        (new GenerateFhirBulkExportJob($job->id))->handle(app(BulkExporter::class));

        $contents = Storage::disk('local')->get($job->fresh()->path('Patient'));

        $this->assertStringContainsString($mine->id, $contents);
        $this->assertStringNotContainsString(
            $theirs->id,
            $contents,
            'The queued job did not re-establish branch context and exported another branch.',
        );
    }

    public function test_status_reports_202_while_pending_and_200_with_a_manifest_when_complete(): void
    {
        $this->patientIn($this->branchA, 'Asante');

        $job = FhirExportJob::query()->create([
            'branch_id' => $this->branchA->id,
            'requested_by' => $this->user->id,
            'status' => FhirExportStatus::PENDING,
            'types' => ['Patient'],
        ]);

        $statusUrl = "/api/v1/fhir/\$export-status/{$job->id}";

        $this->actingAs($this->user)->getJson($statusUrl)->assertStatus(202);

        (new GenerateFhirBulkExportJob($job->id))->handle(app(BulkExporter::class));

        $manifest = $this->actingAs($this->user)->getJson($statusUrl);

        $manifest->assertStatus(200);
        $manifest->assertJsonPath('requiresAccessToken', true);
        $manifest->assertJsonPath('output.0.type', 'Patient');
        $this->assertNotEmpty($manifest->json('transactionTime'));
        $this->assertGreaterThan(0, $manifest->json('output.0.count'));
    }

    public function test_another_users_export_is_not_visible(): void
    {
        $job = FhirExportJob::query()->create([
            'branch_id' => $this->branchB->id,
            'requested_by' => User::factory()->create(['branch_id' => $this->branchB->id])->id,
            'status' => FhirExportStatus::COMPLETED,
            'types' => ['Patient'],
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/fhir/\$export-status/{$job->id}")
            ->assertStatus(404);
    }

    public function test_a_completed_export_file_can_be_downloaded_by_its_requester(): void
    {
        $this->patientIn($this->branchA, 'Asante');

        $job = FhirExportJob::query()->create([
            'branch_id' => $this->branchA->id,
            'requested_by' => $this->user->id,
            'status' => FhirExportStatus::PENDING,
            'types' => ['Patient'],
        ]);

        (new GenerateFhirBulkExportJob($job->id))->handle(app(BulkExporter::class));

        $this->actingAs($this->user)
            ->get("/api/v1/fhir/\$export-file/{$job->id}/Patient")
            ->assertSuccessful();
    }

    public function test_a_download_is_refused_to_someone_elses_requester(): void
    {
        $other = User::factory()->create(['branch_id' => $this->branchB->id]);

        $job = FhirExportJob::query()->create([
            'branch_id' => $this->branchA->id,
            'requested_by' => $this->user->id,
            'status' => FhirExportStatus::COMPLETED,
            'types' => ['Patient'],
        ]);

        $this->actingAs($other)
            ->get("/api/v1/fhir/\$export-file/{$job->id}/Patient")
            ->assertStatus(404);
    }

    public function test_a_running_export_can_be_cancelled(): void
    {
        $job = FhirExportJob::query()->create([
            'branch_id' => $this->branchA->id,
            'requested_by' => $this->user->id,
            'status' => FhirExportStatus::PENDING,
            'types' => ['Patient'],
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/fhir/\$export-status/{$job->id}")
            ->assertStatus(202);

        $this->assertSame(FhirExportStatus::CANCELLED, $job->fresh()->status);
    }

    public function test_export_requires_authentication(): void
    {
        $this->getJson('/api/v1/fhir/$export')->assertStatus(401);
    }

    /**
     * The confirmation modal tells the user "you will be notified when it is
     * ready". Nothing sent that notification until this test existed, so a
     * completed export was indistinguishable from one that never ran.
     */
    public function test_the_requester_is_notified_when_the_export_completes(): void
    {
        $this->patientIn($this->branchA, 'Asante');

        $job = FhirExportJob::query()->create([
            'branch_id' => $this->branchA->id,
            'requested_by' => $this->user->id,
            'status' => FhirExportStatus::PENDING,
            'types' => ['Patient'],
        ]);

        (new GenerateFhirBulkExportJob($job->id))->handle(app(BulkExporter::class));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->user->id,
            'notifiable_type' => $this->user->getMorphClass(),
        ]);

        $payload = DB::table('notifications')
            ->where('notifiable_id', $this->user->id)
            ->latest('created_at')
            ->value('data');

        $this->assertStringContainsString('FHIR export ready', (string) $payload);
    }

    /**
     * The job must land on a queue a worker actually consumes.
     *
     * It defaulted to a dedicated `fhir-exports` queue, while this project's dev
     * runner starts `queue:listen` with no `--queue` and therefore reads only
     * `default`. Exports were dispatched, sat in the jobs table, and never ran.
     */
    public function test_the_job_is_queued_where_the_default_worker_will_find_it(): void
    {
        Queue::fake();

        $this->requestExport();

        Queue::assertPushed(
            GenerateFhirBulkExportJob::class,
            fn (GenerateFhirBulkExportJob $job): bool => $job->queue === config('fhir.export.queue'),
        );

        $this->assertSame(
            'default',
            config('fhir.export.queue'),
            'The export queue must default to one the standard worker consumes.',
        );
    }
}
