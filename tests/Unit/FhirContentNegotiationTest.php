<?php

namespace Modules\FHIR\Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FHIR\FhirResponse\FhirResponseFactory;
use Modules\FHIR\Http\Middleware\FhirContentNegotiation;
use PHPUnit\Framework\TestCase;

class FhirContentNegotiationTest extends TestCase
{
    private FhirContentNegotiation $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new FhirContentNegotiation(new FhirResponseFactory);
    }

    public function test_passes_application_fhir_json_content_type()
    {
        $request = Request::create('/fhir/Patient', 'POST');
        $request->headers->set('Content-Type', 'application/fhir+json');

        $next = fn (Request $req): JsonResponse => new JsonResponse(['resourceType' => 'Patient']);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_passes_application_json_content_type()
    {
        $request = Request::create('/fhir/Patient', 'POST');
        $request->headers->set('Content-Type', 'application/json');

        $next = fn (Request $req): JsonResponse => new JsonResponse(['resourceType' => 'Patient']);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejects_text_xml_content_type()
    {
        $request = Request::create('/fhir/Patient', 'POST');
        $request->headers->set('Content-Type', 'text/xml');

        $next = fn (Request $req): JsonResponse => new JsonResponse(['resourceType' => 'Patient']);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function test_sets_response_content_type_when_not_set()
    {
        $request = Request::create('/fhir/Patient', 'GET');
        $request->headers->set('Accept', 'application/fhir+json');

        $next = fn (Request $req): JsonResponse => new JsonResponse(['resourceType' => 'Patient']);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));
    }

    public function test_default_accept_header_passes()
    {
        $request = Request::create('/fhir/Patient', 'GET');

        $next = fn (Request $req): JsonResponse => new JsonResponse(['resourceType' => 'Patient']);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_accepts_wildcard()
    {
        $request = Request::create('/fhir/Patient', 'GET');
        $request->headers->set('Accept', '*/*');

        $next = fn (Request $req): JsonResponse => new JsonResponse(['resourceType' => 'Patient']);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejects_text_xml_accept_header()
    {
        $request = Request::create('/fhir/Patient', 'GET');
        $request->headers->set('Accept', 'text/xml');

        $next = fn (Request $req): JsonResponse => new JsonResponse(['resourceType' => 'Patient']);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(406, $response->getStatusCode());
    }

    public function test_accepts_multiple_accept_values_including_fhir_json()
    {
        $request = Request::create('/fhir/Patient', 'GET');
        $request->headers->set('Accept', 'text/html, application/fhir+json, application/json');

        $next = fn (Request $req): JsonResponse => new JsonResponse(['resourceType' => 'Patient']);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));
    }
}
