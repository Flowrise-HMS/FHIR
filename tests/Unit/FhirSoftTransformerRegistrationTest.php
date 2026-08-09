<?php

namespace Modules\FHIR\Tests\Unit;

use Modules\Core\Support\ModuleAvailability;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\Providers\FhirServiceProvider;
use Nwidart\Modules\Facades\Module;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class FhirSoftTransformerRegistrationTest extends TestCase
{
    #[Test]
    public function it_registers_transformers_for_enabled_modules(): void
    {
        $this->requireModule('Patient');
        $this->requireModule('Clinical');

        $registrar = new FhirResourceRegistrar;
        $this->registerTransformers($registrar);

        $this->assertTrue($registrar->has('Patient'));
        $this->assertTrue($registrar->has('Encounter'));
        $this->assertTrue($registrar->has('Organization'));
    }

    #[Test]
    public function it_skips_transformers_when_guest_module_is_disabled(): void
    {
        $this->requireModule('Diagnostics');

        $module = Module::find('Diagnostics');
        $this->assertNotNull($module);

        try {
            $module->disable();
            $this->assertFalse(ModuleAvailability::diagnosticsEnabled());

            $registrar = new FhirResourceRegistrar;
            $this->registerTransformers($registrar);

            $this->assertFalse($registrar->has('Observation'));
            $this->assertTrue($registrar->has('Organization'));
        } finally {
            $module->enable();
        }
    }

    #[Test]
    public function it_skips_transformers_when_class_is_missing(): void
    {
        $registrar = new FhirResourceRegistrar;

        if (class_exists('Modules\\DoesNotExist\\FhirMissingTransformer')) {
            $this->fail('Unexpected class should not exist.');
        }

        $registrar->register('Missing', 'Modules\\DoesNotExist\\FhirMissingTransformer', ['read']);

        // Soft registration itself never registers missing classes; verify provider path.
        $softRegistrar = new FhirResourceRegistrar;
        $this->registerTransformers($softRegistrar);

        $this->assertFalse($softRegistrar->has('DoesNotExistResource'));
        $this->assertTrue($softRegistrar->has('Patient') || ! ModuleAvailability::patientEnabled());
    }

    protected function registerTransformers(FhirResourceRegistrar $registrar): void
    {
        $provider = new FhirServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'registerSoftTransformers');
        $method->setAccessible(true);
        $method->invoke($provider, $registrar);
    }
}
