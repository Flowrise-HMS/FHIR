<?php

namespace Modules\FHIR\Tests\Unit;

use Modules\FHIR\FhirValidation\FhirValidator;
use PHPUnit\Framework\TestCase;

class FhirValidatorTest extends TestCase
{
    private FhirValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FhirValidator(
            __DIR__.'/../../Resources/schemas'
        );
    }

    public function test_valid_minimal_patient_passes()
    {
        $result = $this->validator->validate('Patient', [
            'resourceType' => 'Patient',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_invalid_gender_fails_validation()
    {
        $result = $this->validator->validate('Patient', [
            'resourceType' => 'Patient',
            'gender' => 'invalid_gender',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame('error', $result['errors'][0]['severity']);
    }

    public function test_wrong_resource_type_returns_error()
    {
        $result = $this->validator->validate('Patient', [
            'resourceType' => 'Observation',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Observation', $result['errors'][0]['details']['text']);
        $this->assertStringContainsString('Patient', $result['errors'][0]['details']['text']);
    }

    public function test_valid_minimal_practitioner_passes()
    {
        $result = $this->validator->validate('Practitioner', [
            'resourceType' => 'Practitioner',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_unknown_resource_type_returns_error()
    {
        $result = $this->validator->validate('UnknownResource', [
            'resourceType' => 'UnknownResource',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('No schema found', $result['errors'][0]['details']['text']);
    }
}
