<?php

namespace Modules\FHIR\Tests\Unit;

use Modules\FHIR\Contracts\FhirWritableResourceContract;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Tests\TestCase;

/**
 * A resource may not advertise a write interaction it cannot perform.
 *
 * The registrar's interaction list drives both the CapabilityStatement and the
 * route regexes, so a resource listing `create` or `update` there is telling
 * clients the write is available. Before FhirWritableResourceContract existed the
 * controller mapped the payload and discarded it, answering 201/200 without
 * writing anything — every registered resource claimed writes it did not have.
 *
 * This test is what stops that from silently returning.
 */
class FhirWritableInteractionsTest extends TestCase
{
    private const WRITE_INTERACTIONS = ['create', 'update'];

    public function test_resources_advertising_writes_implement_the_writable_contract(): void
    {
        $registrar = app(FhirResourceRegistrar::class);
        $offenders = [];

        foreach ($registrar->getAll() as $entry) {
            $writes = array_intersect(self::WRITE_INTERACTIONS, $entry['interactions']);

            if ($writes === []) {
                continue;
            }

            $transformer = app($entry['transformer_class']);

            if (! $transformer instanceof FhirWritableResourceContract) {
                $offenders[] = sprintf(
                    '%s advertises [%s] but %s does not implement FhirWritableResourceContract',
                    $entry['resource_type'],
                    implode(', ', $writes),
                    $entry['transformer_class'],
                );
            }
        }

        $this->assertSame([], $offenders, implode(PHP_EOL, $offenders));
    }

    public function test_every_registered_resource_is_routable(): void
    {
        $registrar = app(FhirResourceRegistrar::class);

        $routable = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'fhir/{resource}'))
            ->flatMap(fn ($route) => explode('|', $route->wheres['resource'] ?? ''))
            ->unique()
            ->filter()
            ->all();

        $unroutable = array_values(array_diff(
            array_column($registrar->getAll(), 'resource_type'),
            $routable,
        ));

        $this->assertSame(
            [],
            $unroutable,
            'Registered but unreachable via any route: '.implode(', ', $unroutable),
        );
    }
}
