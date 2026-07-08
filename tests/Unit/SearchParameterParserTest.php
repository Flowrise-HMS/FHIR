<?php

namespace Modules\FHIR\Tests\Unit;

use Modules\FHIR\FhirSearch\SearchParameterParser;
use PHPUnit\Framework\TestCase;

class SearchParameterParserTest extends TestCase
{
    private SearchParameterParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SearchParameterParser;
    }

    public function test_parses_string_param(): void
    {
        $result = $this->parser->parse(['name' => 'John']);

        $this->assertSame(20, $result['_count']);
        $this->assertSame(0, $result['_offset']);
        $this->assertSame([], $result['_sort']);
        $this->assertCount(1, $result['filters']);
        $this->assertSame('name', $result['filters'][0]['name']);
        $this->assertSame('John', $result['filters'][0]['value']);
        $this->assertNull($result['filters'][0]['modifier']);
    }

    public function test_parses_token_param(): void
    {
        $result = $this->parser->parse(['gender' => 'male']);

        $this->assertCount(1, $result['filters']);
        $this->assertSame('gender', $result['filters'][0]['name']);
        $this->assertSame('male', $result['filters'][0]['value']);
        $this->assertNull($result['filters'][0]['system']);
    }

    public function test_parses_token_with_system_and_value(): void
    {
        $result = $this->parser->parse(['identifier' => 'http://example.org|12345']);

        $this->assertCount(1, $result['filters']);
        $this->assertSame('http://example.org', $result['filters'][0]['system']);
        $this->assertSame('12345', $result['filters'][0]['value']);
    }

    public function test_parses_token_with_system_only(): void
    {
        $result = $this->parser->parse(['identifier' => 'http://example.org|']);

        $this->assertCount(1, $result['filters']);
        $this->assertSame('http://example.org', $result['filters'][0]['system']);
        $this->assertSame('', $result['filters'][0]['value']);
    }

    public function test_parses_date_with_ge_prefix(): void
    {
        $result = $this->parser->parse(['birthdate' => 'ge2020-01-01']);

        $this->assertCount(1, $result['filters']);
        $this->assertSame('birthdate', $result['filters'][0]['name']);
        $this->assertSame('ge', $result['filters'][0]['prefix']);
        $this->assertSame('2020-01-01', $result['filters'][0]['value']);
    }

    public function test_parses_date_without_prefix_defaults_eq(): void
    {
        $result = $this->parser->parse(['birthdate' => '2020-01-01']);

        $this->assertCount(1, $result['filters']);
        $this->assertSame('birthdate', $result['filters'][0]['name']);
        $this->assertNull($result['filters'][0]['prefix']);
        $this->assertSame('2020-01-01', $result['filters'][0]['value']);
    }

    public function test_parses_exact_modifier(): void
    {
        $result = $this->parser->parse(['name:exact' => 'John']);

        $this->assertCount(1, $result['filters']);
        $this->assertSame('name', $result['filters'][0]['name']);
        $this->assertSame('exact', $result['filters'][0]['modifier']);
        $this->assertSame('John', $result['filters'][0]['value']);
    }

    public function test_parses_contains_modifier(): void
    {
        $result = $this->parser->parse(['name:contains' => 'John']);

        $this->assertCount(1, $result['filters']);
        $this->assertSame('name', $result['filters'][0]['name']);
        $this->assertSame('contains', $result['filters'][0]['modifier']);
        $this->assertSame('John', $result['filters'][0]['value']);
    }

    public function test_parses_count_param(): void
    {
        $result = $this->parser->parse(['_count' => '50']);

        $this->assertSame(50, $result['_count']);
    }

    public function test_parses_offset_param(): void
    {
        $result = $this->parser->parse(['_offset' => '10']);

        $this->assertSame(10, $result['_offset']);
    }

    public function test_parses_sort_single_asc(): void
    {
        $result = $this->parser->parse(['_sort' => 'name']);

        $this->assertSame([['field' => 'name', 'direction' => 'asc']], $result['_sort']);
    }

    public function test_parses_sort_desc(): void
    {
        $result = $this->parser->parse(['_sort' => '-name']);

        $this->assertSame([['field' => 'name', 'direction' => 'desc']], $result['_sort']);
    }

    public function test_parses_sort_multiple(): void
    {
        $result = $this->parser->parse(['_sort' => 'name,-birthdate']);

        $this->assertSame([
            ['field' => 'name', 'direction' => 'asc'],
            ['field' => 'birthdate', 'direction' => 'desc'],
        ], $result['_sort']);
    }

    public function test_count_max_capped_at_100(): void
    {
        $result = $this->parser->parse(['_count' => '200']);

        $this->assertSame(100, $result['_count']);
    }

    public function test_default_count_is_20(): void
    {
        $result = $this->parser->parse([]);

        $this->assertSame(20, $result['_count']);
    }

    public function test_default_offset_is_0(): void
    {
        $result = $this->parser->parse([]);

        $this->assertSame(0, $result['_offset']);
    }
}
