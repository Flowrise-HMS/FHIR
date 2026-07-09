<?php

namespace Modules\FHIR\Providers;

use Modules\FHIR\FhirResponse\FhirResponseFactory;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\FhirSearch\PaginationHandler;
use Modules\FHIR\FhirSearch\SearchParameterParser;
use Modules\FHIR\FhirSearch\SearchQueryBuilder;
use Modules\FHIR\FhirValidation\FhirValidator;
use Modules\FHIR\Http\Middleware\FhirContentNegotiation;
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
        });
    }
}
