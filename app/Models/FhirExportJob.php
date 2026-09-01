<?php

namespace Modules\FHIR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Modules\FHIR\Enums\FhirExportStatus;

/**
 * An asynchronous FHIR bulk export request.
 *
 * Deliberately a plain Model rather than BaseModel: `branch_id` here is the
 * *subject* of an access check, not something to be silently filtered on. A
 * caller must be told "that export belongs to another branch" by an explicit
 * check in the controller, and the worker must be able to load the row it was
 * handed without depending on a branch context it has not established yet.
 *
 * @property string $id
 * @property string $branch_id
 * @property int $requested_by
 * @property FhirExportStatus $status
 */
class FhirExportJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id',
        'requested_by',
        'status',
        'types',
        'since',
        'filtered_query',
        'transaction_time',
        'output',
        'error',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'status' => FhirExportStatus::class,
        'types' => 'array',
        'output' => 'array',
        'since' => 'datetime',
        'transaction_time' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', User::class), 'requested_by');
    }

    /**
     * Total resources written across every file in this export.
     */
    public function resourceCount(): int
    {
        return (int) collect($this->output ?? [])->sum('count');
    }

    /**
     * Directory holding this export's NDJSON files, relative to the configured disk.
     */
    public function directory(): string
    {
        return trim((string) config('fhir.export.directory', 'fhir-exports'), '/')."/{$this->id}";
    }

    public function path(string $resourceType): string
    {
        return $this->directory()."/{$resourceType}.ndjson";
    }

    public function disk(): string
    {
        return (string) config('fhir.export.disk', 'local');
    }

    /**
     * Remove the generated files. Safe to call when nothing was written.
     */
    public function deleteFiles(): void
    {
        Storage::disk($this->disk())->deleteDirectory($this->directory());
    }

    public function isReadableBy(int $userId, ?string $branchId): bool
    {
        return $this->requested_by === $userId
            && $branchId !== null
            && (string) $this->branch_id === (string) $branchId;
    }
}
