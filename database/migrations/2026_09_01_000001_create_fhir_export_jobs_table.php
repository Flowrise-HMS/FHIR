<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks asynchronous FHIR bulk export requests.
 *
 * `branch_id` and `requested_by` are not metadata — they are the access control.
 * The generated NDJSON files contain PHI for exactly one branch, requested by one
 * user, so both the worker (to re-establish branch context, which a queued job does
 * not inherit from any request) and the download route (to decide who may read the
 * files) depend on these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fhir_export_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->string('status', 16)->default('pending');

            // Requested resource types; null means every exportable type.
            $table->json('types')->nullable();
            $table->timestamp('since')->nullable();

            /*
             * A serialized Eloquent builder (anourvalar/eloquent-serialize) when the
             * export came from a Filament table and must honour the user's filters.
             * Null for API-initiated exports, which cover the whole type.
             */
            $table->longText('filtered_query')->nullable();

            $table->timestamp('transaction_time')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fhir_export_jobs');
    }
};
