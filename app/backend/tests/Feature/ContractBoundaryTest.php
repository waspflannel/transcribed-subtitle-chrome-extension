<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContractBoundaryTest extends TestCase
{
    public function test_canonical_contract_files_are_available_to_backend(): void
    {
        $contractsPath = realpath(base_path('../..')).DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.'contracts';
        $schemaPath = $contractsPath.DIRECTORY_SEPARATOR.'schemas';

        $requiredFiles = [
            $contractsPath.DIRECTORY_SEPARATOR.'openapi.json',
            $schemaPath.DIRECTORY_SEPARATOR.'create-subtitle-job-request.schema.json',
            $schemaPath.DIRECTORY_SEPARATOR.'job-response.schema.json',
            $schemaPath.DIRECTORY_SEPARATOR.'track-response.schema.json',
            $schemaPath.DIRECTORY_SEPARATOR.'cue.schema.json',
            $schemaPath.DIRECTORY_SEPARATOR.'token.schema.json',
            $schemaPath.DIRECTORY_SEPARATOR.'api-error.schema.json',
            $schemaPath.DIRECTORY_SEPARATOR.'error-object.schema.json',
        ];

        foreach ($requiredFiles as $requiredFile) {
            $this->assertFileExists($requiredFile);
        }

        $openApi = json_decode(
            file_get_contents($contractsPath.DIRECTORY_SEPARATOR.'openapi.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('3.1.0', $openApi['openapi']);
        $this->assertArrayHasKey('/v1/subtitle-jobs', $openApi['paths']);
        $this->assertArrayNotHasKey('/v1/subtitle-jobs/{jobId}', $openApi['paths']);
        $this->assertArrayNotHasKey('/v1/tracks/lookup', $openApi['paths']);
    }
}
