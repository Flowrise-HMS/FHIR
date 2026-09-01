<?php

namespace Modules\FHIR\FhirBundle;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Modules\FHIR\Contracts\FhirResourceContract;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;

/**
 * Bulk export of registered FHIR resources as newline-delimited JSON.
 *
 * Output is NDJSON — one complete FHIR resource per line — which is the format
 * the FHIR Bulk Data specification defines for export files, and the reason this
 * streams rather than building a Bundle: a Bundle has to be assembled whole in
 * memory before the first byte can be sent, which is exactly what fails on the
 * datasets bulk export exists for.
 *
 * Every resource is chunked and yielded, so peak memory is one chunk regardless
 * of how many records exist.
 *
 * Branch scoping is inherited, not reimplemented: each transformer's `query()`
 * returns an Eloquent builder, so any model carrying the BelongsToBranch global
 * scope is filtered to the caller's branch by that scope. Models without it are
 * not branch-filtered here — see the note on `exportableTypes()`.
 */
class BulkExporter
{
    /** Rows loaded per query while streaming. */
    public const CHUNK_SIZE = 500;

    public function __construct(protected FhirResourceRegistrar $registrar) {}

    /**
     * Resource types available to export: everything registered as readable.
     *
     * @return list<string>
     */
    public function exportableTypes(): array
    {
        return array_column(
            array_filter(
                $this->registrar->getAll(),
                static fn (array $entry): bool => in_array('read', $entry['interactions'], true),
            ),
            'resource_type',
        );
    }

    /**
     * Stream NDJSON lines for the requested types.
     *
     * @param  list<string>  $types  empty means every exportable type
     * @return Generator<int, string>
     */
    public function stream(array $types = [], ?string $since = null): Generator
    {
        $selected = $types === []
            ? $this->exportableTypes()
            : array_values(array_intersect($types, $this->exportableTypes()));

        foreach ($selected as $resourceType) {
            $entry = $this->registrar->get($resourceType);

            if ($entry === null) {
                continue;
            }

            /** @var FhirResourceContract $transformer */
            $transformer = app($entry['transformer_class']);

            yield from $this->streamType($transformer, $since);
        }
    }

    /**
     * Stream one resource type from a caller-supplied query.
     *
     * Used when an export must honour a Filament table's filters: the builder comes
     * from `getTableQueryForExport()`, so it is already both filtered and
     * branch-scoped, and the transformer is used only to shape each row.
     *
     * @return Generator<int, string>
     */
    public function streamQuery(string $resourceType, Builder $query, ?string $since = null): Generator
    {
        $entry = $this->registrar->get($resourceType);

        if ($entry === null) {
            return;
        }

        /** @var FhirResourceContract $transformer */
        $transformer = app($entry['transformer_class']);

        // Carry over the eager loads the transformer's own query() declares —
        // without them toFhir() either N+1s or silently omits relations.
        $query->with($transformer->query()->getEagerLoads());

        yield from $this->streamBuilder($transformer, $query, $since);
    }

    /**
     * @return Generator<int, string>
     */
    private function streamType(FhirResourceContract $transformer, ?string $since): Generator
    {
        yield from $this->streamBuilder($transformer, $transformer->query(), $since);
    }

    /**
     * @return Generator<int, string>
     */
    private function streamBuilder(FhirResourceContract $transformer, Builder $query, ?string $since): Generator
    {

        /*
         * `_since` is the Bulk Data spec's incremental-export filter. It is applied
         * only where the model actually records an update time — filtering on a
         * column that does not exist would fail the whole export, and silently
         * ignoring the parameter would hand the client a full export it believes
         * to be incremental. Types without timestamps are therefore exported whole,
         * which is the safe direction: too much data, never too little.
         */
        if ($since !== null && $this->hasTimestamps($transformer)) {
            $query->where($query->getModel()->getUpdatedAtColumn(), '>=', $since);
        }

        foreach ($query->lazy(self::CHUNK_SIZE) as $model) {
            $encoded = json_encode($transformer->toFhir($model), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded !== false) {
                yield $encoded."\n";
            }
        }
    }

    private function hasTimestamps(FhirResourceContract $transformer): bool
    {
        $model = $transformer->query()->getModel();

        return $model->usesTimestamps() && $model->getUpdatedAtColumn() !== null;
    }
}
