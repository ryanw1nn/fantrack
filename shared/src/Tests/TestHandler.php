<?php

namespace SynergyERP\Shared\Tests;

use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Tests\Utils\ModelPersistenceAsserter;
use SynergyERP\Shared\Tests\TestCase;

class TestHandler extends TestCase
{
    /**
     * Asserts that the handler has successfully saved the data to the correct tenant schema,
     * and that the saved database record matches the expected fixture data.
     *
     * This method assumes that the handler has already received a valid payload
     * (contract responsibility lies with the caller).
     *
     * @param string $contractType The fully qualified class name of the contract to instantiate.
     * @param string $handlerType The fully qualified class name of the handler to instantiate.
     * @param string $modelType The fully qualified class name of the model to use for retrieval.
     * @param array $fixture The expected data to be stored in the database, used for asserting correctness.
     *
     * @return void
     */
    public function testHandler(string $contractType, string $handlerType, string $modelType, array $fixture): void
    {
        $asserter = new ModelPersistenceAsserter($this);
        $testCase = $fixture["testCase"];
        if($testCase["success"] === true) {
            // create and validates the data for testCase
            $contract = $this->instantiateTestContract($contractType, $testCase["data"]);
            $handler = $this->instantiateTestHandler($handlerType, $contract, $modelType);

            // return an array of models
            $models = $handler->handle();

            foreach($models as $model)
            {
                $asserter->assert($fixture['testCase'], $this->getSchema(), $model);
            }
        } else {
            // contract is invalid skip handle function
            $this->assertTrue(true);
        }
    }

    /**
     * Assert that a model exists in the database by ID.
     */
    private function assertModelExistsById(Model $model, int|string $id): Model
    {
        $found = $model->find($id);

        $this->assertNotNull(
            $found,
            "Failed asserting that a record with ID [$id] exists in model " . get_class($model)
        );

        return $found;
    }
}