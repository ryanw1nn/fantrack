<?php

namespace SynergyERP\Shared\Tests\Utils;

class FixtureValidator
{
    public static function validate(array $fixture): void
    {
        if (!isset($fixture['testCase']) || !is_array($fixture['testCase'])) {
            throw new \InvalidArgumentException("Missing or invalid 'testCase' key.");
        }

        if (!isset($fixture['transaction']) || !is_array($fixture['transaction'])) {
            throw new \InvalidArgumentException("Missing or invalid 'transaction' key.");
        }

        $testCase = $fixture['testCase'];

        foreach (['success', 'data', 'message'] as $key) {
            if (!array_key_exists($key, $testCase)) {
                throw new \InvalidArgumentException("Missing '{$key}' in 'testCase'.");
            }
        }

        if (!is_bool($testCase['success'])) {
            throw new \InvalidArgumentException("'testCase.success' must be a boolean.");
        }

        if (!is_array($testCase['data'])) {
            throw new \InvalidArgumentException("'testCase.data' must be an array.");
        }

        if (!is_string($testCase['message'])) {
            throw new \InvalidArgumentException("'testCase.message' must be a string.");
        }

        if ($testCase['success'] === false) {
            if (!array_key_exists('errors', $testCase)) {
                throw new \InvalidArgumentException("'errors' must be provided when 'success' is false.");
            }

            if (!is_array($testCase['errors'])) {
                throw new \InvalidArgumentException("'testCase.errors' must be an array.");
            }
        }

        $transaction = $fixture['transaction'];

        foreach (['key', 'schema'] as $key) {
            if (!array_key_exists($key, $transaction)) {
                throw new \InvalidArgumentException("Missing '{$key}' in 'transaction'.");
            }

            if (!is_string($transaction[$key]) || trim($transaction[$key]) === '') {
                throw new \InvalidArgumentException("'transaction.{$key}' must be a non-empty string.");
            }
        }
    }
}
