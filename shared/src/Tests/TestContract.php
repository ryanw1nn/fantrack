<?php

namespace SynergyERP\Shared\Tests;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use SynergyERP\Shared\Tests\Utils\FixtureValidator;
use SynergyERP\Shared\Contracts\Contract;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use SynergyERP\Shared\Tests\TestCase;
use SynergyERP\Shared\ManifestLoader;

/**
 * Base test contract class for validation testing
*/
class TestContract
{
    /**
     * default contract test runner
     *
     * @param object $contract Contract object with validate() and getErrors() methods
     * @param array $fixture Test fixture with success, errors, and message keys
     */
    public static function testCommandContract(
        string $contractClass, 
        array $fixture, 
        string $model,
        string $action,
        TestCase $case
    ): void {

        // Validate fixture
        FixtureValidator::validate($fixture);   

        // Run test
        if($fixture["testCase"]["success"] === true) {
            self::testValidContract($contractClass, $fixture, $model, $action, $case);
        } else {
            self::testInvalidContract($contractClass, $fixture, $model, $action, $case);
        }

    }

    /**
     * Search query contract test runner with searchable columns support
     *
     * @param string $contractClass Contract class name
     * @param array $fixture Test fixture with success, errors, and message keys
     * @param string $model Model name
     * @param array $searchableColumns Array of searchable column names
     * @param TestCase $case Test case instance
     */
    public static function testSearchContract(
        string $contractClass, 
        array $fixture, 
        string $model,
        array $searchableColumns,
        TestCase $case
    ): void {

        // Validate fixture
        FixtureValidator::validate($fixture);   

        // Run test
        if($fixture["testCase"]["success"] === true) {
            self::testValidSearchContract($contractClass, $fixture, $model, $searchableColumns, $case);
        } else {
            self::testInvalidSearchContract($contractClass, $fixture, $model, $searchableColumns, $case);
        }

    }

    private static function testValidContract(string $contractClass, array $fixture, string $model, string $action, TestCase $case): void
    {
        try {
            $contract = self::createContract($contractClass, $fixture, $model, $action, $case);
            $case->assertTrue(true);
        } catch (ValidationException $e) {
            $case->fail('Validation failed when it should have passed: ' . json_encode($e->errors()));
        }
    }

    private static function testInvalidContract(string $contractClass, array $fixture, string $model, string $action, TestCase $case): void
    {
        try {
            $contract = self::createContract($contractClass, $fixture, $model, $action, $case);
            $case->fail("Validation passed when it should have failed.");
        } catch (ValidationException $e) {
            $errors = $e->validator->errors()->all();
            $case->assertEqualsCanonicalizing($fixture["testCase"]["errors"], $errors);
            $case->assertTrue(true);
        }
    }

    private static function testValidSearchContract(string $contractClass, array $fixture, string $model, array $searchableColumns, TestCase $case): void
    {
        try {
            $contract = self::createSearchContract($contractClass, $fixture, $model, $searchableColumns, $case);
            $case->assertTrue(true);
        } catch (ValidationException $e) {
            $case->fail('Validation failed when it should have passed: ' . json_encode($e->errors()));
        }
    }

    private static function testInvalidSearchContract(string $contractClass, array $fixture, string $model, array $searchableColumns, TestCase $case): void
    {
        try {
            $contract = self::createSearchContract($contractClass, $fixture, $model, $searchableColumns, $case);
            $case->fail("Validation passed when it should have failed.");
        } catch (ValidationException $e) {
            $errors = $e->validator->errors()->all();
            $case->assertEqualsCanonicalizing($fixture["testCase"]["errors"], $errors);
            $case->assertTrue(true);
        }
    }

    private static function createContract(string $contractClass, array $fixture, string $model, string $action, TestCase $case): Contract
    {
        $context = OperationKeyContext::fromOperationKey($fixture['transaction']['key']);
        
    // Check if it's a query contract (needs table parameter as 3rd arg)
    if ($contractClass === 'SynergyERP\Shared\Contracts\Queries\FetchQueryContract' ||
        is_subclass_of($contractClass, 'SynergyERP\Shared\Contracts\Queries\FetchQueryContract') ||
        $contractClass === 'SynergyERP\Shared\Contracts\Queries\SearchQueryContract' ||
        is_subclass_of($contractClass, 'SynergyERP\Shared\Contracts\Queries\SearchQueryContract')) {
        $tableName = self::getTableNameFromModel($model, $case);
        return new $contractClass($fixture["testCase"]["data"], $case->getSchema(), $tableName, $context);
    }
        
        return new $contractClass($fixture["testCase"]["data"], $case->getSchema(), $context);
    }
    
    private static function createSearchContract(string $contractClass, array $fixture, string $model, array $searchableColumns, TestCase $case): Contract
    {
        $context = OperationKeyContext::fromOperationKey($fixture['transaction']['key']);
        return new $contractClass($fixture["testCase"]["data"], $case->getSchema(), $model, $searchableColumns, $context);
    }

    private static function getTableNameFromModel(string $model, TestCase $case): string
    {
        // Use ManifestLoader to get the table name from the model
        $manifestLoader = ManifestLoader::load();
        $manifest = $manifestLoader->getManifest();
        
        if (isset($manifest['models'][$model]['eloquent']['table'])) {
            return $manifest['models'][$model]['eloquent']['table'];
        }
        
        // Fallback: convert model name to snake_case plural
        return Str::snake(Str::plural($model));
    }
}