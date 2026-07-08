<?php

namespace Modules\FHIR\Providers;

use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\FhirResponse\FhirResponseFactory;
use Modules\FHIR\FhirSearch\SearchParameterParser;
use Modules\FHIR\FhirSearch\SearchQueryBuilder;
use Modules\FHIR\FhirSearch\PaginationHandler;
use Modules\FHIR\FhirValidation\FhirValidator;
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
            \Modules\FHIR\Http\Middleware\FhirContentNegotiation::class
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
            $registrar->register('Patient', \Modules\Patient\Classes\Fhir\FhirPatientTransformer::class);
            $registrar->register('Practitioner', \Modules\Staff\Classes\Fhir\FhirPractitionerTransformer::class);
            $registrar->register('PractitionerRole', \Modules\Staff\Classes\Fhir\FhirPractitionerRoleTransformer::class);
        });
    }
}
