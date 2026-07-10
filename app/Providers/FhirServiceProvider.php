<?php

namespace Modules\FHIR\Providers;

use Modules\Appointment\Classes\Fhir\FhirAppointmentResponseTransformer;
use Modules\Appointment\Classes\Fhir\FhirAppointmentTransformer;
use Modules\Clinical\Classes\Fhir\FhirAllergyIntoleranceTransformer;
use Modules\Clinical\Classes\Fhir\FhirConditionTransformer;
use Modules\Clinical\Classes\Fhir\FhirEncounterTransformer;
use Modules\Core\Classes\Fhir\FhirHealthcareServiceTransformer;
use Modules\Core\Classes\Fhir\FhirLocationTransformer;
use Modules\Core\Classes\Fhir\FhirOrganizationTransformer;
use Modules\Diagnostics\Classes\Fhir\FhirObservationTransformer;
use Modules\FHIR\FhirResponse\FhirResponseFactory;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\FhirSearch\PaginationHandler;
use Modules\FHIR\FhirSearch\SearchParameterParser;
use Modules\FHIR\FhirSearch\SearchQueryBuilder;
use Modules\FHIR\FhirValidation\FhirValidator;
use Modules\FHIR\Http\Middleware\FhirContentNegotiation;
use Modules\Inventory\Classes\Fhir\FhirInventoryItemTransformer;
use Modules\Patient\Classes\Fhir\FhirPatientTransformer;
use Modules\Staff\Classes\Fhir\FhirPractitionerRoleTransformer;
use Modules\Staff\Classes\Fhir\FhirPractitionerTransformer;
use Nwidart\Modules\Support\ModuleServiceProvider;

class FhirServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'FHIR';

    protected string $nameLower = 'fhir';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->app['router']->aliasMiddleware(
            'fhir.negotiation',
            FhirContentNegotiation::class
        );
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(FhirResourceRegistrar::class);
        $this->app->singleton(FhirResponseFactory::class);
        $this->app->singleton(FhirValidator::class, fn ($app) => new FhirValidator(module_path('FHIR', 'Resources/schemas')));
        $this->app->singleton(SearchParameterParser::class);
        $this->app->singleton(SearchQueryBuilder::class);
        $this->app->singleton(PaginationHandler::class);

        $this->app->resolving(FhirResourceRegistrar::class, function ($registrar) {
            $registrar->register('Patient', FhirPatientTransformer::class);
            $registrar->register('Practitioner', FhirPractitionerTransformer::class);
            $registrar->register('PractitionerRole', FhirPractitionerRoleTransformer::class);
            $registrar->register('Appointment', FhirAppointmentTransformer::class, ['read', 'search-type']);
            $registrar->register('AppointmentResponse', FhirAppointmentResponseTransformer::class, ['read', 'search-type']);
            if (class_exists(FhirOrganizationTransformer::class)) {
                $registrar->register('Organization', FhirOrganizationTransformer::class, ['read', 'search-type']);
            }

            if (class_exists(FhirLocationTransformer::class)) {
                $registrar->register('Location', FhirLocationTransformer::class, ['read', 'search-type']);
            }

            if (class_exists(FhirHealthcareServiceTransformer::class)) {
                $registrar->register('HealthcareService', FhirHealthcareServiceTransformer::class, ['read', 'search-type']);
            }

            if (class_exists(FhirEncounterTransformer::class)) {
                $registrar->register('Encounter', FhirEncounterTransformer::class, ['read', 'search-type']);
            }

            if (class_exists(FhirObservationTransformer::class)) {
                $registrar->register('Observation', FhirObservationTransformer::class, ['read', 'search-type']);
            }

            if (class_exists(FhirConditionTransformer::class)) {
                $registrar->register('Condition', FhirConditionTransformer::class, ['read', 'search-type']);
            }

            if (class_exists(FhirAllergyIntoleranceTransformer::class)) {
                $registrar->register('AllergyIntolerance', FhirAllergyIntoleranceTransformer::class, ['read', 'search-type']);
            }

            if (class_exists(FhirInventoryItemTransformer::class)) {
                $registrar->register('InventoryItem', FhirInventoryItemTransformer::class, ['read', 'search-type']);
            }
        });
    }
}
