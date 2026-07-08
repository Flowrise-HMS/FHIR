<?php

namespace Modules\FHIR\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FHIR\FhirRouting\FhirResourceRegistrar;

class CapabilityController extends Controller
{
    public function __construct(
        protected FhirResourceRegistrar $registrar,
    ) {}

    public function metadata(): JsonResponse
    {
        $resources = $this->registrar->getAll();

        return new JsonResponse([
            'resourceType' => 'CapabilityStatement',
            'status' => 'active',
            'date' => now()->toIso8601String(),
            'publisher' => 'FlowRise HMS',
            'kind' => 'instance',
            'software' => [
                'name' => 'FlowRise FHIR Server',
                'version' => '1.0.0',
            ],
            'fhirVersion' => '4.0.1',
            'format' => ['application/fhir+json', 'application/json'],
            'rest' => [
                [
                    'mode' => 'server',
                    'documentation' => 'FlowRise HMS FHIR R4 API',
                    'security' => [
                        'description' => 'Bearer token authentication via HTTP Authorization header (Sanctum)',
                        'service' => [
                            [
                                'coding' => [
                                    [
                                        'system' => 'http://terminology.hl7.org/CodeSystem/restful-security-service',
                                        'code' => 'Bearer',
                                        'display' => 'Bearer',
                                    ],
                                ],
                                'text' => 'Bearer token',
                            ],
                        ],
                    ],
                    'resource' => array_map(fn ($entry) => [
                        'type' => $entry['resource_type'],
                        'interaction' => [
                            ['code' => 'read'],
                            ['code' => 'search-type'],
                            ['code' => 'create'],
                            ['code' => 'update'],
                            ['code' => 'delete'],
                        ],
                        'versioning' => 'no-version',
                        'readHistory' => false,
                        'updateCreate' => false,
                    ], $resources),
                ],
            ],
        ], 200, ['Content-Type' => 'application/fhir+json']);
    }
}
