<?php

namespace Modules\FHIR\FhirRouting;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\FHIR\Contracts\FhirResourceContract;

/**
 * Who may do what to which FHIR resource.
 *
 * FHIR is another door to the same records, so it asks the same question as the
 * panel and the REST API: the policy of the model behind the resource. Models
 * without a policy of their own name the relation to the record that governs
 * them (`through` in the registrar): reading needs `view` on that parent and any
 * write needs `update` on it. A resource with neither is refused (super admins
 * excepted, who pass every gate).
 */
class FhirAuthorizer
{
    private const ABILITIES = [
        'read' => 'view',
        'search-type' => 'viewAny',
        'export' => 'viewAny',
        'create' => 'create',
        'update' => 'update',
        'delete' => 'delete',
    ];

    public function __construct(protected FhirResourceRegistrar $registrar) {}

    /**
     * @param  string  $interaction  read|search-type|export|create|update|delete
     */
    public function allows(
        string $interaction,
        FhirResourceContract $transformer,
        ?Model $record = null,
        ?Authorizable $user = null,
    ): bool {
        $ability = self::ABILITIES[$interaction] ?? null;
        $gate = $this->gateFor($user);

        if ($ability === null || $gate === null) {
            return false;
        }

        $through = $this->registrar->get($transformer->resourceType())['through'] ?? null;

        if ($through !== null) {
            return $this->allowsThrough($gate, $ability, $transformer, $through, $record);
        }

        $subject = $record ?? $transformer->query()->getModel()::class;

        return Gate::getPolicyFor($subject) !== null && $gate->allows($ability, $subject);
    }

    /**
     * Find a record the resource's own query would return.
     *
     * A transformer's findById() may be wider than its query() (some include
     * trashed records, some start from an unscoped model). The query is what
     * carries branch isolation and consent, so a record outside it is reported
     * as not found.
     */
    public function find(FhirResourceContract $transformer, string $id): ?Model
    {
        $record = $transformer->findById($id);

        if ($record === null) {
            return null;
        }

        $query = $transformer->query();
        $model = $query->getModel();

        // Organization also resolves branch ids; those are a different model.
        if (! $record instanceof $model) {
            return $record;
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->whereKey($record->getKey())->exists() ? $record : null;
    }

    private function allowsThrough(
        GateContract $gate,
        string $ability,
        FhirResourceContract $transformer,
        string $through,
        ?Model $record,
    ): bool {
        $parentClass = $this->parentClass($transformer->query()->getModel(), $through);

        if (Gate::getPolicyFor($parentClass) === null) {
            return false;
        }

        if ($ability === 'viewAny') {
            return $gate->allows('viewAny', $parentClass);
        }

        // Nothing exists yet on create; the transformer's business rules resolve
        // the referenced parent within the caller's branch.
        $parent = $record === null ? new $parentClass : data_get($record, $through);

        return $parent instanceof Model
            && $gate->allows($ability === 'view' ? 'view' : 'update', $parent);
    }

    /**
     * @return class-string<Model>
     */
    private function parentClass(Model $model, string $through): string
    {
        foreach (explode('.', $through) as $relation) {
            $model = $model->{$relation}()->getRelated();
        }

        return $model::class;
    }

    private function gateFor(?Authorizable $user): ?GateContract
    {
        $user ??= Auth::user();

        return $user === null ? null : Gate::forUser($user);
    }
}
