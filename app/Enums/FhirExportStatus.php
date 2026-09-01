<?php

namespace Modules\FHIR\Enums;

enum FhirExportStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }

    public function isInProgress(): bool
    {
        return in_array($this, [self::PENDING, self::RUNNING], true);
    }
}
