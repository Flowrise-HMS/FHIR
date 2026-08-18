<?php

namespace Modules\FHIR\Providers;

use Modules\Core\Support\ModuleAvailability;
use Modules\FHIR\FhirResponse\FhirResponseFactory;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\FhirSearch\PaginationHandler;
use Modules\FHIR\FhirSearch\SearchParameterParser;
use Modules\FHIR\FhirSearch\SearchQueryBuilder;
use Modules\FHIR\FhirValidation\FhirValidator;
use Modules\FHIR\Http\Middleware\FhirContentNegotiation;
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

        $this->app->resolving(FhirResourceRegistrar::class, function (FhirResourceRegistrar $registrar): void {
            $this->registerSoftTransformers($registrar);
        });
    }

    protected function registerSoftTransformers(FhirResourceRegistrar $registrar): void
    {
        /**
         * @var list<array{0: string, 1: class-string, 2: list<string>, 3: ?string}>
         */
        $transformers = [
            ['Patient', 'Modules\\Patient\\Classes\\Fhir\\FhirPatientTransformer', ['read', 'search-type', 'create', 'update', 'delete'], 'Patient'],
            ['Practitioner', 'Modules\\Staff\\Classes\\Fhir\\FhirPractitionerTransformer', ['read', 'search-type', 'create', 'update', 'delete'], 'Staff'],
            ['PractitionerRole', 'Modules\\Staff\\Classes\\Fhir\\FhirPractitionerRoleTransformer', ['read', 'search-type', 'create', 'update', 'delete'], 'Staff'],
            ['Appointment', 'Modules\\Appointment\\Classes\\Fhir\\FhirAppointmentTransformer', ['read', 'search-type'], 'Appointment'],
            ['AppointmentResponse', 'Modules\\Appointment\\Classes\\Fhir\\FhirAppointmentResponseTransformer', ['read', 'search-type'], 'Appointment'],
            ['CarePlan', 'Modules\\Clinical\\Classes\\Fhir\\FhirCarePlanTransformer', ['read', 'search-type'], 'Clinical'],
            ['Goal', 'Modules\\Clinical\\Classes\\Fhir\\FhirGoalTransformer', ['read', 'search-type'], 'Clinical'],
            ['EpisodeOfCare', 'Modules\\MCH\\Classes\\Fhir\\FhirEpisodeOfCareTransformer', ['read', 'search-type'], 'MCH'],
            ['Immunization', 'Modules\\MCH\\Classes\\Fhir\\FhirImmunizationTransformer', ['read', 'create'], 'MCH'],
            ['Organization', 'Modules\\Core\\Classes\\Fhir\\FhirOrganizationTransformer', ['read', 'search-type'], null],
            ['Location', 'Modules\\Core\\Classes\\Fhir\\FhirLocationTransformer', ['read', 'search-type'], null],
            ['HealthcareService', 'Modules\\Core\\Classes\\Fhir\\FhirHealthcareServiceTransformer', ['read', 'search-type'], null],
            ['Encounter', 'Modules\\Clinical\\Classes\\Fhir\\FhirEncounterTransformer', ['read', 'search-type'], 'Clinical'],
            ['Observation', 'Modules\\Diagnostics\\Classes\\Fhir\\FhirObservationTransformer', ['read', 'search-type'], 'Diagnostics'],
            ['Condition', 'Modules\\Clinical\\Classes\\Fhir\\FhirConditionTransformer', ['read', 'search-type'], 'Clinical'],
            ['AllergyIntolerance', 'Modules\\Clinical\\Classes\\Fhir\\FhirAllergyIntoleranceTransformer', ['read', 'search-type'], 'Clinical'],
            ['InventoryItem', 'Modules\\Inventory\\Classes\\Fhir\\FhirInventoryItemTransformer', ['read', 'search-type'], 'Inventory'],
        ];

        foreach ($transformers as [$resourceType, $transformerClass, $interactions, $module]) {
            if ($module !== null && ! ModuleAvailability::enabled($module)) {
                continue;
            }

            if (! class_exists($transformerClass)) {
                continue;
            }

            $registrar->register($resourceType, $transformerClass, $interactions);
        }
    }
}
