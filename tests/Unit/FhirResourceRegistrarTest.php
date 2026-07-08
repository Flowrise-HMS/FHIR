<?php

namespace Modules\FHIR\Tests\Unit;

use Modules\FHIR\Contracts\FhirResourceContract;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use PHPUnit\Framework\TestCase;

class FhirResourceRegistrarTest extends TestCase
{
    public function test_can_register_and_retrieve_resource()
    {
        $registrar = new FhirResourceRegistrar;
        $transformer = $this->createStub(FhirResourceContract::class);
        $transformer->method('resourceType')->willReturn('Patient');
        $registrar->register('Patient', get_class($transformer));
        $this->assertNotNull($registrar->get('Patient'));
        $this->assertSame('Patient', $registrar->get('Patient')['resource_type']);
    }

    public function test_get_returns_null_for_unregistered()
    {
        $this->assertNull((new FhirResourceRegistrar)->get('Unknown'));
    }

    public function test_get_all_returns_all()
    {
        $registrar = new FhirResourceRegistrar;
        $t1 = $this->createStub(FhirResourceContract::class);
        $t1->method('resourceType')->willReturn('Patient');
        $t2 = $this->createStub(FhirResourceContract::class);
        $t2->method('resourceType')->willReturn('Practitioner');
        $registrar->register('Patient', get_class($t1));
        $registrar->register('Practitioner', get_class($t2));
        $this->assertCount(2, $registrar->getAll());
    }
}
