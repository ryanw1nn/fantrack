<?php

namespace SynergyERP\Shared\Tests;

use PHPUnit\Framework\TestCase;
use SynergyERP\Shared\Contracts\Commands\BaseCommandContract;

/**
 * Unit tests for BaseCommandContract::fillServerGeneratedRefs(). Covers the
 * server-owned columns this helper still mints (ulid, version) using fixture
 * manifest blocks and an anonymous subclass that stubs getCurrentModelData()
 * and bypasses the parent constructor — the full Contract lifecycle
 * (validate → Validator::make → Log::info) needs a Laravel container we
 * don't want to bootstrap here.
 *
 * public_id and public_ref live in mintServerIdentity() (called from
 * afterValidation) and round-trip the auth-service, so they're covered by
 * integration tests rather than this unit harness.
 */
final class BaseCommandContractFillRefsTest extends TestCase
{
    public function test_populates_ulid_when_member_declared(): void
    {
        $contract = $this->makeContract(
            modelData: [
                'members' => ['ulid' => ['type' => 'CHAR(26)']],
                'eloquent' => ['table' => 'ulid'],
            ],
            requestData: [],
        );

        $this->invokeFill($contract);
        $data = $this->readRequestData($contract);

        $this->assertArrayHasKey('ulid', $data);
        $this->assertMatchesRegularExpression(
            '/^[0-9A-HJKMNP-TV-Z]{26}$/',
            $data['ulid'],
            'ulid must be a 26-char Crockford-base32 ULID',
        );
    }

    public function test_seeds_placeholder_public_id_and_public_ref(): void
    {
        // beforeValidation has to seed *something* here so concrete
        // contracts whose rules() hardcode `required` for these columns
        // don't 422 before afterValidation can mint the real values.
        // mintServerIdentity() (run from afterValidation) overwrites
        // both with an auth-service ULID and a prefix-based public_ref.
        $contract = $this->makeContract(
            modelData: [
                'members' => [
                    'public_id'  => ['type' => 'CHAR(26)'],
                    'public_ref' => ['type' => 'VARCHAR(32)'],
                ],
                'eloquent' => ['table' => 'project', 'prefix' => 'PRJ'],
            ],
            requestData: [],
        );

        $this->invokeFill($contract);
        $data = $this->readRequestData($contract);

        $this->assertArrayHasKey('public_id', $data);
        $this->assertArrayHasKey('public_ref', $data);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $data['public_id']);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $data['public_ref']);
    }

    public function test_is_idempotent_when_ulid_already_present(): void
    {
        $preset = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $contract = $this->makeContract(
            modelData: [
                'members' => ['ulid' => ['type' => 'CHAR(26)']],
                'eloquent' => ['table' => 'ulid'],
            ],
            requestData: ['ulid' => $preset],
        );

        $this->invokeFill($contract);
        $data = $this->readRequestData($contract);

        $this->assertSame(
            $preset,
            $data['ulid'],
            'fill must not overwrite a ulid that already carries a value',
        );
    }

    public function test_seeds_version_when_non_nullable_and_missing(): void
    {
        $contract = $this->makeContract(
            modelData: [
                'members' => ['version' => ['type' => 'INT', 'isNullable' => false]],
                'eloquent' => ['table' => 'project'],
            ],
            requestData: [],
        );

        $this->invokeFill($contract);
        $data = $this->readRequestData($contract);

        $this->assertSame(1, $data['version']);
    }

    private function makeContract(array $modelData, array $requestData): BaseCommandContract
    {
        $contract = new class($modelData) extends BaseCommandContract {
            public function __construct(private array $stubbedModelData)
            {
                // Intentionally bypasses parent constructor — see class docblock.
            }

            protected function getCurrentModelData(): ?array
            {
                return $this->stubbedModelData;
            }

            public function callFillServerGeneratedRefs(): void
            {
                $this->fillServerGeneratedRefs();
            }

            public function seedRequestData(array $data): void
            {
                $this->requestData = $data;
            }

            public function readRequestData(): array
            {
                return $this->requestData;
            }
        };

        $contract->seedRequestData($requestData);
        return $contract;
    }

    private function invokeFill(BaseCommandContract $contract): void
    {
        $contract->callFillServerGeneratedRefs();
    }

    private function readRequestData(BaseCommandContract $contract): array
    {
        return $contract->readRequestData();
    }
}
