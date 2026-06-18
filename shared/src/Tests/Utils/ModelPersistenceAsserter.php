<?php


namespace SynergyERP\Shared\Tests\Utils;

use Illuminate\Foundation\Testing\TestCase;
use SynergyERP\Shared\Models\Base\TenantModel;
/**
 * Assert that a Laravel Eloquent model matches the expected data.
 */
final class ModelPersistenceAsserter
{
    protected TestCase $testCase;

    public function __construct(TestCase $testCase)
    {
        $this->testCase = $testCase;
    }

    /**
     * Assert that the model was persisted correctly and matches the expected fixture.
     *
     * @param array $testCase The expected data to compare the model too
     * @param string $schema The tenant schema used to access the correct database
     * @param TenantModel $model The model instance (empty, used for fetching)
     */
    public function assert(array $testCase, string $schema, TenantModel $model): void
    {
        //$this->assertModelSchema($model, $schema);
        //$dbModel = $this->assertModelExistsById($model, $model->id);
        $fieldsToCheck = $model->getFillable();
        $this->assertModelFieldsMatch($model, $testCase, $fieldsToCheck);
    }

    /**
     * Assert that a model exists in the database by ID.
     */
    private function assertModelExistsById(object $model, int|string $id): object
    {
        $found = $model->find($id);

        $this->testCase->assertNotNull(
            $found,
            "Failed asserting that a record with ID [$id] exists in model " . get_class($model)
        );

        return $found;
    }

    /**
     * Assert that the given model's fields match either the fixture or the response.
     *
     * @param TenantModel $model
     * @param array $testCase
     * @param array $fieldsToCheck
     */
    private function assertModelFieldsMatch(TenantModel $model, array $testCase, array $fieldsToCheck): void
    {
        //iterate though field in the expected data
        foreach ($fieldsToCheck as $field) {
            // check if the field is in the fixture
            if (isset($testCase['data'][$field])) {
                if (in_array($field, ['start_date', 'due_date', 'end_date'])) {
                    // this is a special case for date fields
                    $this->testCase->assertNotNull($model->$field, "Expected $field to be set");
                    $this->testCase->assertEquals(
                        substr($testCase['data'][$field], 0, 10),
                        $model->$field->toDateString(),
                        "$field does not match expected value"
                    );
                } else {
                    // this is for cases that are not date fields
                    $this->testCase->assertEquals(
                        $testCase['data'][$field],
                        $model->$field,
                        "Mismatch on field: $field"
                    );
                }
            }
        }
    }
}
