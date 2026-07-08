<?php

namespace Modules\FHIR\Tests\Unit;

use Modules\FHIR\FhirSearch\PaginationHandler;
use PHPUnit\Framework\TestCase;

class PaginationHandlerTest extends TestCase
{
    private PaginationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new PaginationHandler;
    }

    public function test_generates_self_link_from_base_url_and_params(): void
    {
        $links = $this->handler->generateLinks('/fhir/Patient', ['name' => 'John'], 50, 20, 0);

        $this->assertArrayHasKey('self', $links);
        $this->assertSame('/fhir/Patient?name=John&_count=20&_offset=0', $links['self']);
    }

    public function test_generates_next_link_when_there_are_more_results(): void
    {
        $links = $this->handler->generateLinks('/fhir/Patient', [], 50, 20, 0);

        $this->assertArrayHasKey('self', $links);
        $this->assertArrayHasKey('next', $links);
        $this->assertSame('/fhir/Patient?_count=20&_offset=20', $links['next']);
    }

    public function test_no_next_link_on_last_page(): void
    {
        $links = $this->handler->generateLinks('/fhir/Patient', [], 50, 20, 40);

        $this->assertArrayHasKey('self', $links);
        $this->assertArrayNotHasKey('next', $links);
    }

    public function test_generates_first_and_last_links(): void
    {
        $links = $this->handler->generateLinks('/fhir/Patient', [], 100, 20, 40);

        $this->assertArrayHasKey('first', $links);
        $this->assertArrayHasKey('last', $links);
        $this->assertSame('/fhir/Patient?_count=20&_offset=0', $links['first']);
        $this->assertSame('/fhir/Patient?_count=20&_offset=80', $links['last']);
    }

    public function test_first_link_uses_offset_zero(): void
    {
        $links = $this->handler->generateLinks('/fhir/Patient', ['gender' => 'male'], 30, 10, 10);

        $this->assertArrayHasKey('first', $links);
        $this->assertStringContainsString('_offset=0', $links['first']);
        $this->assertStringContainsString('gender=male', $links['first']);
        $this->assertStringContainsString('_count=10', $links['first']);
    }
}
