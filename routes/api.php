<?php

use Illuminate\Support\Facades\Route;
use Modules\FHIR\Http\Controllers\CapabilityController;
use Modules\FHIR\Http\Controllers\FhirController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('fhir/metadata', [CapabilityController::class, 'metadata']);

    Route::middleware([\Modules\FHIR\Http\Middleware\FhirContentNegotiation::class])->group(function () {
        Route::get('fhir/{resource}/{id}', [FhirController::class, 'read'])
            ->where('resource', 'Patient|Practitioner|PractitionerRole');
        Route::get('fhir/{resource}', [FhirController::class, 'search'])
            ->where('resource', 'Patient|Practitioner|PractitionerRole');
        Route::post('fhir/{resource}', [FhirController::class, 'create'])
            ->where('resource', 'Patient|Practitioner|PractitionerRole');
        Route::put('fhir/{resource}/{id}', [FhirController::class, 'update'])
            ->where('resource', 'Patient|Practitioner|PractitionerRole');
        Route::delete('fhir/{resource}/{id}', [FhirController::class, 'destroy'])
            ->where('resource', 'Patient|Practitioner|PractitionerRole');
    });
});
