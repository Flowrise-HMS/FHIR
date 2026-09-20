# FHIR module

**In one sentence:** The FHIR module exposes a FHIR R4 REST API (read/search, CRUD for a few types, Bundle import and bulk `$export`) for national and third-party health data exchange.

## Current status

**In progress.** Infrastructure (validation, search, pagination, capability statement, content negotiation), Bundle import and bulk export are built and many resource types are registered. SMART on FHIR and the remaining clinical/financial resources remain planned.

See [Module Status](../../docs/shared/module-status.md) for the canonical rollout matrix. Verified against code on 2026-09-20.

## What is implemented

- **API base:** `/api/v1/fhir/{resource}` (Sanctum-authenticated; `FhirContentNegotiation` middleware)
- **Metadata:** `GET /api/v1/fhir/metadata` (capability statement)
- **Full CRUD:** Patient (Patient module transformer), Practitioner and PractitionerRole (Staff module transformers)
- **Read/search:** Organization, Location, HealthcareService (Core); Encounter, Condition, AllergyIntolerance, CarePlan, Goal (Clinical); Observation (Diagnostics); Appointment, AppointmentResponse (Appointment); InventoryItem (Inventory); EpisodeOfCare (MCH)
- **Read only:** Immunization (MCH)
- **Bundle import:** `POST /api/v1/fhir` (transaction/batch bundles, `BundleImporter`)
- **Bulk export:** `GET /api/v1/fhir/$export` (`_type`, `_since`; 202 + `Content-Location`), `GET|DELETE /api/v1/fhir/$export-status/{export}`, `GET /api/v1/fhir/$export-file/{export}/{type}` (NDJSON via temporary signed URL or the token owner). Runs on the queue (`GenerateFhirBulkExportJob`, `FhirExportJob` model, `BulkExporter`).
- **Admin panel:** "Export FHIR" header action (modal **Export as FHIR**: confirmation only, no options; **Start export** queues an NDJSON export of the rows matching the current filters and notifies when ready) and "Export Selected as FHIR" bulk action on the Patients list (permission `export_fhir`)
- **Command:** `php artisan fhir:prune-exports {--dry-run}` removes export files/records past `FHIR_EXPORT_RETENTION_DAYS` (scheduled daily by `FhirServiceProvider::configureSchedules()`)
- **Infrastructure:** `FhirResourceRegistrar` (route constraints derived per interaction), `FhirValidator`, search parameter handling

## Configuration

`config/config.php` (`config('fhir.export.*')`): `FHIR_EXPORT_QUEUE` (default `default`), `FHIR_EXPORT_DISK` (default `local`; must be a private disk), `FHIR_EXPORT_RETENTION_DAYS` (7). A queue worker must be running for exports to complete.

## What is deferred

- Additional FHIR resources (DiagnosticReport, Claim, Coverage, ServiceRequest, Medication*, DocumentReference, Schedule, Slot, SupplyDelivery, etc.)
- Full CRUD for resources currently exposed as read/search only
- SMART on FHIR authentication
- CCD document generation

## Dependencies

- Registers transformers from domain modules (Patient, Staff, Core, Clinical, Diagnostics, Appointment, Inventory, MCH) when those modules are enabled
- Does not require every clinical module at runtime; registrations use `class_exists` guards where appropriate

## For developers

- **Namespace:** `Modules\FHIR\...`
- **Service provider:** `Modules\FHIR\Providers\FhirServiceProvider`
- **Routes:** `Modules/FHIR/routes/api.php` (`routes/web.php` still holds a scaffold `Route::resource('fhirs')` with placeholder views that is not part of the API)
- **Tests:** `Modules/FHIR/tests/` (17 test files: capability, bundle import, bulk export, appointment/care-plan integration, infrastructure unit tests); domain modules include transformer unit tests
- **Docs:** [API Reference](../../docs/developer-guide/api-reference.md)
