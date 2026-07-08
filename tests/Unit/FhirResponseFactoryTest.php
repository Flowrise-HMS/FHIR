<?php

namespace Modules\FHIR\Tests\Unit;

use Modules\FHIR\FhirResponse\FhirResponseFactory;
use PHPUnit\Framework\TestCase;

class FhirResponseFactoryTest extends TestCase
{
    private FhirResponseFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new FhirResponseFactory;
    }

    public function test_resource_returns_200_with_fhir_content_type()
    {
        $resource = ['resourceType' => 'Patient', 'id' => '123'];

        $response = $this->factory->resource($resource);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));
        $this->assertSame($resource, $response->getData(true));
    }

    public function test_created_returns_201_with_location_and_etag()
    {
        $resource = ['resourceType' => 'Patient', 'id' => '123'];

        $response = $this->factory->created($resource, 'Patient', '123');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));
        $this->assertSame('/fhir/Patient/123', $response->headers->get('Location'));
        $this->assertSame('W/"123"', $response->headers->get('ETag'));
        $this->assertSame($resource, $response->getData(true));
    }

    public function test_updated_returns_200_with_fhir_content_type()
    {
        $resource = ['resourceType' => 'Patient', 'id' => '123'];

        $response = $this->factory->updated($resource);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));
        $this->assertSame($resource, $response->getData(true));
    }

    public function test_deleted_returns_204()
    {
        $response = $this->factory->deleted();

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_search_set_returns_bundle_with_searchset_type()
    {
        $entries = [
            ['resourceType' => 'Patient', 'id' => '1'],
            ['resourceType' => 'Patient', 'id' => '2'],
        ];

        $response = $this->factory->searchSet($entries, 2);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));

        $body = $response->getData(true);
        $this->assertSame('Bundle', $body['resourceType']);
        $this->assertSame('searchset', $body['type']);
        $this->assertSame(2, $body['total']);
        $this->assertCount(2, $body['entry']);
        $this->assertSame(['resource' => $entries[0]], $body['entry'][0]);
        $this->assertSame(['resource' => $entries[1]], $body['entry'][1]);
    }

    public function test_search_set_includes_link_array_when_links_provided()
    {
        $entries = [['resourceType' => 'Patient', 'id' => '1']];

        $response = $this->factory->searchSet($entries, 1, [
            'self' => '/fhir/Patient?_count=10',
            'next' => '/fhir/Patient?_count=10&page=2',
        ]);

        $body = $response->getData(true);
        $this->assertArrayHasKey('link', $body);
        $this->assertCount(2, $body['link']);
        $this->assertSame(['relation' => 'self', 'url' => '/fhir/Patient?_count=10'], $body['link'][0]);
        $this->assertSame(['relation' => 'next', 'url' => '/fhir/Patient?_count=10&page=2'], $body['link'][1]);
    }

    public function test_search_set_omits_link_when_no_links_provided()
    {
        $response = $this->factory->searchSet([['resourceType' => 'Patient', 'id' => '1']], 1);

        $body = $response->getData(true);
        $this->assertArrayNotHasKey('link', $body);
    }

    public function test_operation_outcome_returns_valid_structure()
    {
        $issues = [
            ['severity' => 'error', 'code' => 'value', 'details' => ['text' => 'Invalid value']],
        ];

        $response = $this->factory->operationOutcome($issues);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));

        $body = $response->getData(true);
        $this->assertSame('OperationOutcome', $body['resourceType']);
        $this->assertSame($issues, $body['issue']);
    }

    public function test_operation_outcome_custom_status_code()
    {
        $issues = [['severity' => 'error', 'code' => 'not-found', 'details' => ['text' => 'Not found']]];

        $response = $this->factory->operationOutcome($issues, 404);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_not_found_returns_404_with_operation_outcome()
    {
        $response = $this->factory->notFound('Patient', '999');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));

        $body = $response->getData(true);
        $this->assertSame('OperationOutcome', $body['resourceType']);
        $this->assertCount(1, $body['issue']);
        $this->assertSame('error', $body['issue'][0]['severity']);
        $this->assertSame('not-found', $body['issue'][0]['code']);
        $this->assertSame('Patient with id 999 not found', $body['issue'][0]['details']['text']);
        $this->assertSame(['Patient.id'], $body['issue'][0]['expression']);
    }

    public function test_unsupported_media_type_returns_415()
    {
        $response = $this->factory->unsupportedMediaType();

        $this->assertSame(415, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));

        $body = $response->getData(true);
        $this->assertSame('OperationOutcome', $body['resourceType']);
        $this->assertSame('Content-Type must be application/fhir+json or application/json', $body['issue'][0]['details']['text']);
    }

    public function test_not_acceptable_returns_406()
    {
        $response = $this->factory->notAcceptable();

        $this->assertSame(406, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));

        $body = $response->getData(true);
        $this->assertSame('OperationOutcome', $body['resourceType']);
        $this->assertSame('Accept header must include application/fhir+json', $body['issue'][0]['details']['text']);
    }

    public function test_unauthorized_returns_401()
    {
        $response = $this->factory->unauthorized();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));

        $body = $response->getData(true);
        $this->assertSame('OperationOutcome', $body['resourceType']);
        $this->assertSame('Authentication required', $body['issue'][0]['details']['text']);
    }

    public function test_forbidden_returns_403()
    {
        $response = $this->factory->forbidden();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));

        $body = $response->getData(true);
        $this->assertSame('OperationOutcome', $body['resourceType']);
        $this->assertSame('Insufficient permissions', $body['issue'][0]['details']['text']);
    }

    public function test_validation_error_returns_422()
    {
        $issues = [
            ['severity' => 'error', 'code' => 'required', 'details' => ['text' => 'Name is required'], 'expression' => ['Patient.name']],
        ];

        $response = $this->factory->validationError($issues);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('application/fhir+json', $response->headers->get('Content-Type'));

        $body = $response->getData(true);
        $this->assertSame('OperationOutcome', $body['resourceType']);
        $this->assertSame($issues, $body['issue']);
    }
}
