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
        $resourceTypes = array_map(fn ($r) => $r['resource_type'], $resources);

        return response()->json([
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
                    'resource' => array_map(fn ($type) => [
                        'type' => $type,
                        'interaction' => [
                            ['code' => 'read'],
                            ['code' => 'search-type'],
                            ['code' => 'create'],
                            ['code' => 'update'],
                            ['code' => 'delete'],
                        ],
                    ], $resourceTypes),
                ],
            ],
        ]);
    }
}
