<?php

use Illuminate\Support\Facades\Route;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;
use Modules\FHIR\Http\Controllers\CapabilityController;
use Modules\FHIR\Http\Controllers\FhirBundleController;
use Modules\FHIR\Http\Controllers\FhirController;
use Modules\FHIR\Http\Controllers\FhirExportStatusController;
use Modules\FHIR\Http\Middleware\FhirContentNegotiation;

/*
| The resource constraint for each interaction is derived from the registrar
| rather than hand-written.
|
| These five regexes were previously maintained by hand and had already drifted:
| EpisodeOfCare and Immunization were registered in FhirServiceProvider but absent
| from every regex, so they were registered, advertised in the CapabilityStatement,
| and unreachable. Deriving them means a resource becomes routable exactly when it
| is registered, for exactly the interactions it declares.
*/

$registrar = app(FhirResourceRegistrar::class);

$typesSupporting = static function (string $interaction) use ($registrar): string {
    $types = array_column(
        array_filter(
            $registrar->getAll(),
            static fn (array $entry): bool => in_array($interaction, $entry['interactions'], true),
        ),
        'resource_type',
    );

    // A regex that matches nothing, so the route simply never resolves rather
    // than matching every resource type.
    return $types === [] ? '(*FAIL)' : implode('|', $types);
};

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () use ($typesSupporting) {
    Route::get('fhir/metadata', [CapabilityController::class, 'metadata']);

    /*
     | Bundle import and bulk export. Both are declared before the {resource}
     | routes so `$export` is not swallowed by the resource-type placeholder.
     */
    Route::post('fhir', [FhirBundleController::class, 'import'])->name('fhir.bundle.import');
    Route::get('fhir/$export', [FhirBundleController::class, 'export'])->name('fhir.bulk.export');

    Route::get('fhir/$export-status/{export}', [FhirExportStatusController::class, 'show'])
        ->name('fhir.export.status');
    Route::delete('fhir/$export-status/{export}', [FhirExportStatusController::class, 'destroy'])
        ->name('fhir.export.cancel');
    Route::get('fhir/$export-file/{export}/{type}', [FhirExportStatusController::class, 'file'])
        ->name('fhir.export.file');

    Route::middleware([FhirContentNegotiation::class])->group(function () use ($typesSupporting) {
        Route::get('fhir/{resource}/{id}', [FhirController::class, 'read'])
            ->where('resource', $typesSupporting('read'));
        Route::get('fhir/{resource}', [FhirController::class, 'search'])
            ->where('resource', $typesSupporting('search-type'));
        Route::post('fhir/{resource}', [FhirController::class, 'create'])
            ->where('resource', $typesSupporting('create'));
        Route::put('fhir/{resource}/{id}', [FhirController::class, 'update'])
            ->where('resource', $typesSupporting('update'));
        Route::delete('fhir/{resource}/{id}', [FhirController::class, 'destroy'])
            ->where('resource', $typesSupporting('delete'));
    });
});
