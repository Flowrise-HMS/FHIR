<?php

namespace Modules\FHIR\Console\Commands;

use Illuminate\Console\Command;
use Modules\FHIR\Models\FhirExportJob;

/**
 * Deletes expired bulk-export files and their job rows.
 *
 * Export files are unencrypted PHI sitting on disk. Without this they accumulate
 * for the life of the installation, so retention is a requirement rather than
 * housekeeping. Schedule it daily.
 */
class PruneFhirExportsCommand extends Command
{
    protected $signature = 'fhir:prune-exports {--dry-run : List what would be removed without deleting anything}';

    protected $description = 'Delete FHIR bulk export files and records past their retention window';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = FhirExportJob::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired FHIR exports.');

            return self::SUCCESS;
        }

        foreach ($expired as $job) {
            $this->line(sprintf(
                '%s export %s (branch %s, expired %s)',
                $dryRun ? 'Would remove' : 'Removing',
                $job->id,
                $job->branch_id,
                $job->expires_at?->toDateTimeString() ?? 'unknown',
            ));

            if ($dryRun) {
                continue;
            }

            // Files first: a deleted row with orphaned files on disk is the one
            // outcome that leaves PHI with nothing tracking it.
            $job->deleteFiles();
            $job->delete();
        }

        $this->info(sprintf(
            '%s %d expired FHIR export(s).',
            $dryRun ? 'Would remove' : 'Removed',
            $expired->count(),
        ));

        return self::SUCCESS;
    }
}
