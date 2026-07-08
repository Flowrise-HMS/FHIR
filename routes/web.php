<?php

use Illuminate\Support\Facades\Route;
use Modules\FHIR\Http\Controllers\FHIRController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('fhirs', FHIRController::class)->names('fhir');
});
