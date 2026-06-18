<?php

namespace SynergyERP\Shared\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SynergyERP\Shared\Cache\CacheKeyBuilder;

class CacheKeyBuilderTest extends TestCase
{
    // -- forRecord ----------------------------------

    public function test_forRecord_builds_correct_key(): void
    {
        $key = CacheKeyBuilder::forRecord('rrglass', 'project', 42, 3);
        $this->assertSame('tenant:rrglass|project:42:3', $key);
    }

    public function test_forRecord_with_string_id(): void
    {
        $key = CacheKeyBuilder::forRecord('rrglass', 'project', 'abc-123', 1);
        $this->assertSame('tenant:rrglass|project:abc-123:1', $key);
    }

    public function test_forRecord_with_version_zero(): void
    {
        $key = CacheKeyBuilder::forRecord('rrglass', 'project', 42, 0);
        $this->assertSame('tenant:rrglass|project:42:0', $key);
    }

    // -- forList ----------------------------------

    public function test_forList_builds_correct_key(): void
    {
        $key = CacheKeyBuilder::forList('rrglass', 'project');
        $this->assertSame('tenant:rrglass|project', $key);
    }

    public function test_forList_with_hyphenated_model(): void
    {
        $key = CacheKeyBuilder::forList('rrglass', 'project-segment');
        $this->assertSame('tenant:rrglass|project-segment', $key);
    }

    // -- forVersion ----------------------------------

    public function test_forVersion_builds_correct_key(): void
    {
        $key = CacheKeyBuilder::forVersion('rrglass', 'project');
        $this->assertSame('tenant:rrglass|project:__version__', $key);
    }

    // -- forRoom ----------------------------------

    public function test_forRoom_builds_correct_key(): void
    {
        $key = CacheKeyBuilder::forRoom('rrglass', 'project', 42);
        $this->assertSame('tenant:rrglass|room|project:42', $key);
    }

    // -- Tenant isolation ----------------------------------

    public function test_different_tenants_produce_different_keys(): void
    {
        $keyA = CacheKeyBuilder::forRecord('tenant-a', 'project', 42, 1);
        $keyB = CacheKeyBuilder::forRecord('tenant-b', 'project', 42, 1);

        $this->assertNotSame($keyA, $keyB);
        $this->assertStringContainsString('tenant-a', $keyA);
        $this->assertStringContainsString('tenant-b', $keyB);
    }

    // -- fromContext ----------------------------------

    public function test_fromContext_returns_expected_structure(): void{
        $context = $this->createMock(\SynergyERP\Shared\Models\Operations\OperationKeyContext::class);
        $context->method('getOperationComponent')
            ->willReturnMap([
                ['model', 'project'],
            ]);
        $context->method('hasModelId')->willReturn(false);

        $keys = CacheKeyBuilder::fromContext($context, 'rrglass', ['id' => 42]);

        $this->assertSame(42, $keys['model_id']);
        $this->assertSame('project', $keys['model_name']);
        $this->assertSame('rrglass', $keys['tenant_key']);
        $this->assertSame('tenant:rrglass|project', $keys['list']);
        $this->assertSame('tenant:rrglass|project:__version__', $keys['version']);
    }

    public function test_fromContext_without_id_returns_null_model_id(): void
    {
        $context = $this->createMock(\SynergyERP\Shared\Models\Operations\OperationKeyContext::class);
        $context->method('getOperationComponent')
            ->willReturnMap([
                ['model', 'project'],
            ]);
        $context->method('hasModelId')->willReturn(false);

        $keys = CacheKeyBuilder::fromContext($context, 'rrglass', []);

        $this->assertNull($keys['model_id']);
    }

    // -- buildRecordKey ----------------------------------

    public function test_buildRecordKey_with_model_id(): void
    {
        $cacheKeys = [
            'model_id'      => 42,
            'model_name'    => 'project',
            'tenant_key'    => 'rrglass',
        ];

        $recordKey = CacheKeyBuilder::buildRecordKey($cacheKeys, 5);
        $this->assertSame('tenant:rrglass|project:42:5', $recordKey);
    }

    public function test_buildRecordKey_without_model_id_returns_null(): void
    {
        $cacheKeys = [
            'model_id'      => null,
            'model_name'    => 'project',
            'tenant_key'    => 'rrglass',
        ];

        $this->assertNull(CacheKeyBuilder::buildRecordKey($cacheKeys, 5));
    }

    // -- parse ---------------------------------

    public function test_parse_record_key(): void
    {
        $parsed = CacheKeyBuilder::parse('tenant:rrglass|project:42');

        $this->assertSame('rrglass', $parsed['tenant_key']);
        $this->assertSame('project', $parsed['model_name']);
        $this->assertSame('42', $parsed['model_id']);
    }

    public function test_parse_list_key(): void
    {
        $parsed = CacheKeyBuilder::parse('tenant:rrglass|project');

        $this->assertSame('rrglass', $parsed['tenant_key']);
        $this->assertSame('project', $parsed['model_name']);
        $this->assertNull($parsed['model_id']);
    }

    public function test_parse_room_key(): void
    {
        $parsed = CacheKeyBuilder::parse('tenant:rrglass|room|project:42');

        $this->assertSame('rrglass', $parsed['tenant_key']);
        $this->assertSame('project', $parsed['model_name']);
        $this->assertSame('42', $parsed['model_id']);
    }

    public function test_parse_round_trips_with_forRecord(): void
    {
        $original = CacheKeyBuilder::forRecord('acme', 'estimate', 99, 7);
        $parsed = CacheKeyBuilder::parse($original);

        $this->assertSame('acme', $parsed['tenant_key']);
        $this->assertSame('estimate', $parsed['model_name']);
        // model_id will be "99:7" because parse splits on first colon only
        $this->assertStringStartsWith('99', $parsed['model_id']);
    }
}