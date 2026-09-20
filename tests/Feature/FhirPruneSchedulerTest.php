<?php

namespace Modules\FHIR\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FhirPruneSchedulerTest extends TestCase
{
    public function test_prune_exports_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'fhir:prune-exports'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    public function test_scaffold_resource_routes_are_gone(): void
    {
        $this->assertFalse(Route::has('fhir.index'));
        $this->assertFalse(Route::has('clinical.index'));
        $this->assertFalse(Route::has('insurance.index'));
    }
}
