<?php

namespace SynergyERP\Shared\Tests;

use Illuminate\Support\Facades\DB;
use Tests\TestCase as BaseTestCase;
use SynergyERP\Shared\Services\JwtHelper;
use SynergyERP\Shared\Contracts\Contract;
use SynergyERP\Shared\Handlers\BaseHandler;
use SynergyERP\Shared\Models\Base\TenantModel;

abstract class TestCase extends BaseTestCase
{
    private string $schema;

    public static function loadFixtures(): \Generator
    {
        yield from []; // Override in test classes that need fixtures
    }
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'rrglasswindows';
        $this->assertDatabaseConnection('mysql');
    }

    /**
     * Assert that a database connection is valid.
     *
     * @param string $connection
     * @throws \RuntimeException
    */
    protected function assertDatabaseConnection(string $connection): void
    {
        try {
            DB::connection($connection)->getPdo();
        } catch (\Exception $e) {
            throw new \RuntimeException("Database connection [{$connection}] failed: " . $e->getMessage());
        }
    }

    /**
     * Assert that the model is using the correct schema/connection (optional).
     * This assumes your model sets the connection name like "tenant_{schema}".
     */
    public function assertModelSchema(TenantModel $model, string $expectedSchema): void
    {
        $connection = $model->getTenantSchema();

        $this->assertEquals(
            $expectedSchema,
            $connection,
            "expected schema connection: {$expectedSchema} but got {$connection}"
        );
    }


    /**
     * Extract subdomain from AUTH_TOKEN in .env.testing file
     *
     * @param string|null $envPath Path to .env.testing file (optional)
     * @return string The subdomain extracted from the token
     * @throws \RuntimeException If AUTH_TOKEN or JWT_SECRET is not found or invalid
     */
    protected function getSchemaFromEnvAuth(?string $envPath = null): string
    {
        // Use provided path or check common locations
        $envPath = $envPath ?? '/var/www/html/.env.testing';
        if (!file_exists($envPath)) {
            // Try base_path as fallback
            $envPath = base_path('.env.testing');
            if (!file_exists($envPath)) {
                throw new \RuntimeException("Environment file not found: {$envPath}");
            }
        }

        // Parse the .env file
        $envContent = file_get_contents($envPath);
        $lines = explode("\n", $envContent);
        $envVariables = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2) + [null, null];
            if ($name && $value) {
                $envVariables[trim($name)] = trim($value);
            }
        }

        // Check for required variables
        if (!isset($envVariables['AUTH_TOKEN']) || empty($envVariables['AUTH_TOKEN'])) {
            throw new \RuntimeException('AUTH_TOKEN not found in environment file');
        }

        if (!isset($envVariables['JWT_SECRET']) || empty($envVariables['JWT_SECRET'])) {
            throw new \RuntimeException('JWT_SECRET not found in environment file');
        }

        // Extract payload and get subdomain
        try {
            $payload = JwtHelper::extractPayload($envVariables['AUTH_TOKEN'], $envVariables['JWT_SECRET']);

            if (!isset($payload['subdomain'])) {
                throw new \RuntimeException('Subdomain not found in JWT payload');
            }

            return $payload['subdomain'];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to extract subdomain from token: ' . $e->getMessage());
        }
    }

    public function getSchema(): string
    {
        return $this->schema;
    }

    protected function getTenantUserId():int
    {
        return 1;
    }

    protected function instantiateTestContract(string $contractType, array $data): Contract
    {
        $contract = new $contractType($data, $this->getSchema());
        $contract->validate();
        return $contract;
    }

    protected function instantiateTestHandler(string $handlerType, Contract $contract, string $modelType): BaseHandler
    {
        $model = $this->instantiateModel($modelType);
        $handler = new $handlerType($contract, $model, $this->getTenantUserId());
        return $handler;
    }

    protected function instantiateModel(string $modelType): TenantModel
    {
        $model = new $modelType();
        return $model;
    }


}