<?php

namespace Modules\FHIR\Tests\Feature;

use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\Http\Controllers\CapabilityController;
use PHPUnit\Framework\TestCase;

class FhirCapabilitiesTest extends TestCase
{
    public function test_metadata_returns_valid_capability_statement_structure()
    {
        $registrar = new FhirResourceRegistrar;
        $controller = new CapabilityController($registrar);
        $response = $controller->metadata();

        $this->assertEquals(200, $response->getStatusCode());
        $data = $response->getData(true);

        $this->assertSame('CapabilityStatement', $data['resourceType']);
        $this->assertSame('4.0.1', $data['fhirVersion']);
        $this->assertSame('instance', $data['kind']);
        $this->assertSame('active', $data['status']);
    }

    public function test_metadata_lists_registered_resources()
    {
        $registrar = new FhirResourceRegistrar;
        $t1 = $this->createStub(\Modules\FHIR\Contracts\FhirResourceContract::class);
        $t1->method('resourceType')->willReturn('Patient');
        $t2 = $this->createStub(\Modules\FHIR\Contracts\FhirResourceContract::class);
        $t2->method('resourceType')->willReturn('Practitioner');
        $registrar->register('Patient', get_class($t1));
        $registrar->register('Practitioner', get_class($t2));

        $controller = new CapabilityController($registrar);
        $response = $controller->metadata();
        $data = $response->getData(true);

        $types = array_map(fn ($r) => $r['type'], $data['rest'][0]['resource']);
        $this->assertContains('Patient', $types);
        $this->assertContains('Practitioner', $types);
    }

    public function test_metadata_includes_security_section()
    {
        $registrar = new FhirResourceRegistrar;
        $controller = new CapabilityController($registrar);
        $response = $controller->metadata();
        $data = $response->getData(true);

        $security = $data['rest'][0]['security'];
        $this->assertNotEmpty($security);
        $this->assertSame('Bearer', $security['service'][0]['coding'][0]['code']);
    }

    public function test_metadata_includes_versioning_for_resources()
    {
        $registrar = new FhirResourceRegistrar;
        $t = $this->createStub(\Modules\FHIR\Contracts\FhirResourceContract::class);
        $t->method('resourceType')->willReturn('Patient');
        $registrar->register('Patient', get_class($t));

        $controller = new CapabilityController($registrar);
        $response = $controller->metadata();
        $data = $response->getData(true);

        $resource = $data['rest'][0]['resource'][0];
        $this->assertArrayHasKey('versioning', $resource);
        $this->assertArrayHasKey('readHistory', $resource);
        $this->assertArrayHasKey('updateCreate', $resource);
    }
}
