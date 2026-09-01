<?php

namespace Modules\FHIR\FhirBundle;

use RuntimeException;

/**
 * Signals that a transaction Bundle entry failed and the whole Bundle must unwind.
 *
 * Carried rather than handled inline so the surrounding DB::transaction sees a
 * throw — that is what triggers the rollback.
 */
class BundleEntryException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $issues
     */
    public function __construct(
        public readonly string $entryStatus,
        public readonly array $issues,
    ) {
        parent::__construct("Bundle entry failed with status {$entryStatus}");
    }
}
