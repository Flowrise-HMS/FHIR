<?php

return [
    'name' => 'FHIR',

    /*
    |--------------------------------------------------------------------------
    | Bulk export
    |--------------------------------------------------------------------------
    |
    | Read as `config('fhir.export.*')` — nwidart merges a module's config under
    | the module's own key, so these do NOT live at `config('export.*')`.
    |
    | The disk must be a private one. Export files are PHI: the `public` disk is
    | web-served and would publish them.
    |
    */
    'export' => [
        /*
         * Defaults to the `default` queue on purpose.
         *
         * A dedicated queue name only works if something is listening to it, and
         * the project's own dev runner (`composer dev`) starts
         * `queue:listen --tries=1 --timeout=0` with no `--queue`, so it consumes
         * `default` and nothing else. Naming a queue here that no worker reads
         * makes exports queue up silently and never run.
         *
         * Set FHIR_EXPORT_QUEUE where a worker is actually dedicated to it, e.g.
         * `php artisan queue:work --queue=fhir-exports`.
         */
        'queue' => env('FHIR_EXPORT_QUEUE', 'default'),
        'disk' => env('FHIR_EXPORT_DISK', 'local'),
        'directory' => 'fhir-exports',
        'retention_days' => (int) env('FHIR_EXPORT_RETENTION_DAYS', 7),
    ],

    'permissions' => [
        'export_fhir' => 'Export FHIR',
    ],
];
