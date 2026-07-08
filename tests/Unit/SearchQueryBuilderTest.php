<?php

namespace Modules\FHIR\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Modules\FHIR\FhirSearch\SearchQueryBuilder;
use PHPUnit\Framework\TestCase;

class BuilderSpy extends Builder
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        $this->calls[] = ['where', [$column, $operator, $value, $boolean]];
        return $this;
    }

    public function orderBy($column, $direction = 'asc'): static
    {
        $this->calls[] = ['orderBy', [$column, $direction]];
        return $this;
    }

    public function whereHas($relation, $callback = null, $operator = '>=', $count = 1): static
    {
        $this->calls[] = ['whereHas', [$relation, $callback, $operator, $count]];
        return $this;
    }
}

class SearchQueryBuilderTest extends TestCase
{
    private SearchQueryBuilder $queryBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queryBuilder = new SearchQueryBuilder;
    }

    public function test_applies_string_like_search_default_starts_with(): void
    {
        $builder = new BuilderSpy;
        $params = [
            '_count' => 20,
            '_offset' => 0,
            '_sort' => [],
            'filters' => [
                ['name' => 'name', 'value' => 'John', 'modifier' => null, 'prefix' => null, 'system' => null],
            ],
        ];
        $searchableParams = ['name' => ['column' => 'name']];

        $this->queryBuilder->apply($builder, $params, $searchableParams);

        $this->assertCount(1, $builder->calls);
        $this->assertSame('where', $builder->calls[0][0]);
        $this->assertSame(['name', 'LIKE', 'John%', 'and'], $builder->calls[0][1]);
    }

    public function test_applies_exact_modifier(): void
    {
        $builder = new BuilderSpy;
        $params = [
            '_count' => 20,
            '_offset' => 0,
            '_sort' => [],
            'filters' => [
                ['name' => 'name', 'value' => 'John', 'modifier' => 'exact', 'prefix' => null, 'system' => null],
            ],
        ];
        $searchableParams = ['name' => ['column' => 'name']];

        $this->queryBuilder->apply($builder, $params, $searchableParams);

        $this->assertCount(1, $builder->calls);
        $this->assertSame('where', $builder->calls[0][0]);
        $this->assertSame(['name', '=', 'John', 'and'], $builder->calls[0][1]);
    }

    public function test_applies_contains_modifier(): void
    {
        $builder = new BuilderSpy;
        $params = [
            '_count' => 20,
            '_offset' => 0,
            '_sort' => [],
            'filters' => [
                ['name' => 'name', 'value' => 'John', 'modifier' => 'contains', 'prefix' => null, 'system' => null],
            ],
        ];
        $searchableParams = ['name' => ['column' => 'name']];

        $this->queryBuilder->apply($builder, $params, $searchableParams);

        $this->assertCount(1, $builder->calls);
        $this->assertSame('where', $builder->calls[0][0]);
        $this->assertSame(['name', 'LIKE', '%John%', 'and'], $builder->calls[0][1]);
    }

    public function test_applies_date_ge_prefix(): void
    {
        $builder = new BuilderSpy;
        $params = [
            '_count' => 20,
            '_offset' => 0,
            '_sort' => [],
            'filters' => [
                ['name' => 'birthdate', 'value' => '2020-01-01', 'modifier' => null, 'prefix' => 'ge', 'system' => null],
            ],
        ];
        $searchableParams = ['birthdate' => ['column' => 'birthdate']];

        $this->queryBuilder->apply($builder, $params, $searchableParams);

        $this->assertCount(1, $builder->calls);
        $this->assertSame('where', $builder->calls[0][0]);
        $this->assertSame(['birthdate', '>=', '2020-01-01', 'and'], $builder->calls[0][1]);
    }

    public function test_applies_sort_order(): void
    {
        $builder = new BuilderSpy;
        $params = [
            '_count' => 20,
            '_offset' => 0,
            '_sort' => [['field' => 'name', 'direction' => 'asc']],
            'filters' => [],
        ];
        $searchableParams = ['name' => ['column' => 'name']];

        $this->queryBuilder->apply($builder, $params, $searchableParams);

        $this->assertCount(1, $builder->calls);
        $this->assertSame('orderBy', $builder->calls[0][0]);
        $this->assertSame(['name', 'asc'], $builder->calls[0][1]);
    }

    public function test_skips_unknown_params(): void
    {
        $builder = new BuilderSpy;
        $params = [
            '_count' => 20,
            '_offset' => 0,
            '_sort' => [],
            'filters' => [
                ['name' => 'unknown_field', 'value' => 'test', 'modifier' => null, 'prefix' => null, 'system' => null],
            ],
        ];
        $searchableParams = ['name' => ['column' => 'name']];

        $this->queryBuilder->apply($builder, $params, $searchableParams);

        $this->assertCount(0, $builder->calls);
    }

    public function test_applies_relation_search_for_identifiers(): void
    {
        $builder = new BuilderSpy;
        $params = [
            '_count' => 20,
            '_offset' => 0,
            '_sort' => [],
            'filters' => [
                ['name' => 'identifier', 'value' => 'http://example.org', 'modifier' => null, 'prefix' => null, 'system' => 'http://example.org'],
            ],
        ];
        $searchableParams = ['identifier' => ['column' => 'value', 'relation' => 'identifiers']];

        $this->queryBuilder->apply($builder, $params, $searchableParams);

        $this->assertCount(1, $builder->calls);
        $this->assertSame('whereHas', $builder->calls[0][0]);
        $this->assertSame('identifiers', $builder->calls[0][1][0]);
    }
}
