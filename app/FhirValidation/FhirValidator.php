<?php

namespace Modules\FHIR\FhirValidation;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

class FhirValidator
{
    protected string $schemaPath;

    protected ?Schema $fullSchema = null;

    public function __construct(?string $schemaPath = null)
    {
        $this->schemaPath = $schemaPath ?? module_path('FHIR', 'Resources/schemas');
    }

    public function validate(string $resourceType, array $resource): array
    {
        $actualType = $resource['resourceType'] ?? null;
        if ($actualType !== $resourceType) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'severity' => 'error',
                        'code' => 'invalid',
                        'details' => ['text' => "Expected resourceType '{$resourceType}', got '".($actualType ?? 'null')."'"],
                        'expression' => ["{$resourceType}.resourceType"],
                    ],
                ],
            ];
        }

        $schemaFile = $this->schemaPath.'/'.$resourceType.'.schema.json';
        if (! file_exists($schemaFile)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'severity' => 'error',
                        'code' => 'invalid',
                        'details' => ['text' => "No schema found: {$resourceType}"],
                    ],
                ],
            ];
        }

        try {
            $this->loadFullSchema();
            $this->fullSchema->in(json_decode(json_encode($resource)));

            return ['valid' => true, 'errors' => []];
        } catch (InvalidValue $e) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'severity' => 'error',
                        'code' => 'invalid',
                        'details' => ['text' => $e->getMessage()],
                        'expression' => [$resourceType],
                    ],
                ],
            ];
        }
    }

    protected function loadFullSchema(): void
    {
        if ($this->fullSchema === null) {
            $schemaData = json_decode(
                file_get_contents($this->schemaPath.'/fhir.schema.json')
            );
            $this->fullSchema = Schema::import($schemaData);
        }
    }
}
