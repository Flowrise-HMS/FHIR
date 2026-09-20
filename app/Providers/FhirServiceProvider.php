<?php

namespace Modules\FHIR\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Classes\Support\PageHeaderActionsRegistry;
use Modules\Core\Classes\Support\TableBulkActionsRegistry;
use Modules\Core\Support\ModuleAvailability;
use Modules\FHIR\Console\Commands\PruneFhirExportsCommand;
use Modules\FHIR\FhirResponse\FhirResponseFactory;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\FhirSearch\PaginationHandler;
use Modules\FHIR\FhirSearch\SearchParameterParser;
use Modules\FHIR\FhirSearch\SearchQueryBuilder;
use Modules\FHIR\FhirValidation\FhirValidator;
use Modules\FHIR\Filament\Actions\ExportFhirAction;
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

        if ($this->app->runningInConsole()) {
            $this->commands([PruneFhirExportsCommand::class]);
        }

        $this->registerExportActions();
    }

    /**
     * Bulk export files are kept for FHIR_EXPORT_RETENTION_DAYS; prune daily.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('fhir:prune-exports')->daily();
    }

    /**
     * Contribute the "Export FHIR" header action to resource list pages.
     *
     * Page classes are named as strings and registered through Core's
     * PageHeaderActionsRegistry so this module reaches into Patient, Staff and the
     * rest without any of them importing FHIR — the boundary rule forbids most of
     * those edges, and it would turn an optional module into a hard dependency.
     *
     * A page only receives these if it merges the registry into its
     * getHeaderActions(); registering for a page that does not is harmless.
     */
    protected function registerExportActions(): void
    {
        $pages = [
            'Modules\\Patient\\Filament\\Clusters\\Patient\\Resources\\Patients\\Pages\\ListPatients' => 'Patient',
        ];

        $tables = [
            'Modules\\Patient\\Filament\\Clusters\\Patient\\Resources\\Patients\\Tables\\PatientsTable' => 'Patient',
        ];

        $pageRegistry = $this->app->make(PageHeaderActionsRegistry::class);

        foreach ($pages as $pageClass => $resourceType) {
            if (! class_exists($pageClass)) {
                continue;
            }

            $pageRegistry->register(
                $pageClass,
                fn (): array => [ExportFhirAction::make($resourceType)],
            );
        }

        $tableRegistry = $this->app->make(TableBulkActionsRegistry::class);

        foreach ($tables as $tableClass => $resourceType) {
            if (! class_exists($tableClass)) {
                continue;
            }

            $tableRegistry->register(
                $tableClass,
                fn (): array => [ExportFhirAction::bulk($resourceType)],
            );
        }
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
            /*
             * Read-only until an import mapping is designed. FhirImmunizationTransformer::fromFhir()
             * throws 'not yet implemented', so the `create` this used to advertise could only ever
             * have 500'd — it was masked purely because the hand-written route regex omitted
             * Immunization entirely. Now that routes derive from this list, advertising it would
             * expose the fault.
             *
             * Implementing it is a domain decision, not a mapping one: ImmunizationRecordService
             * offers administer()/decline() against a dose already scheduled from an immunization
             * schedule, so an inbound FHIR Immunization has to be matched to an existing scheduled
             * record. What to do when no such record exists needs a clinical answer.
             */
            ['Immunization', 'Modules\\MCH\\Classes\\Fhir\\FhirImmunizationTransformer', ['read'], 'MCH'],
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
