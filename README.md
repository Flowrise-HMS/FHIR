# FHIR module

**In one sentence:** The FHIR module exposes a FHIR R4 REST API for national and third-party health data exchange.

## Current status

**In progress.** Infrastructure (validation, search, pagination, capability statement) is built. Multiple resource types are registered. SMART on FHIR, bulk export, and remaining clinical/financial resources remain planned.

See [Module Status](../../docs/shared/module-status.md) for the canonical rollout matrix.

## What is implemented

- **API base:** `/api/v1/fhir/{resource}` (Sanctum-authenticated)
- **Metadata:** `GET /api/v1/fhir/metadata` (capability statement)
- **Full CRUD:** Patient (Patient module transformer), Practitioner and PractitionerRole (Staff module transformers)
- **Read/search:** Organization, Location, HealthcareService (Core transformers); Encounter, Condition, AllergyIntolerance (Clinical); Observation (Diagnostics); Appointment, AppointmentResponse (Appointment); InventoryItem (Inventory)
- **Infrastructure:** `FhirResourceRegistrar`, `FhirValidator`, search parameter handling, content negotiation

## What is deferred

- Additional FHIR resources (DiagnosticReport, Claim, Coverage, ServiceRequest, Medication*, Schedule, Slot, etc.)
- Full CRUD for resources currently exposed as read/search only
- SMART on FHIR authentication
- Bulk data export (`$export`)
- CCD document generation

## Dependencies

- Registers transformers from domain modules (Patient, Staff, Core, Clinical, Diagnostics, Appointment, Inventory) when those modules are enabled
- Does not require every clinical module at runtime; registrations use `class_exists` guards where appropriate

## For developers

- **Namespace:** `Modules\FHIR\...`
- **Service provider:** `Modules\FHIR\Providers\FhirServiceProvider`
- **Routes:** `Modules/FHIR/routes/api.php`
- **Tests:** `Modules/FHIR/tests/` (capability, appointment integration, infrastructure unit tests); domain modules include transformer unit tests
