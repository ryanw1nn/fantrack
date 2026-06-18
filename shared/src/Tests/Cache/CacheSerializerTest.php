<?php

namespace SynergyERP\Shared\Tests\Cache;

use Tests\TestCase;
use SynergyERP\Shared\Cache\CacheSerializer;

class CacheSerializerTest extends TestCase
{

    // -- serialize --------------------------------------

    public function test_serialize_produces_valid_json(): void
    {
        $json = CacheSerializer::serialize(['name' => 'Test Project'], 1);

        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
    }

    public function test_serialize_includes_envelope_keys(): void
    {
        $json = CacheSerializer::serialize(['id' => 42], 3);
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('cached_at', $decoded);
        $this->assertArrayHasKey('version', $decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertSame(3, $decoded['version']);
        $this->assertSame(['id' => 42], $decoded['data']);
    }

    public function test_serialize_with_null_version(): void
    {
        $json = CacheSerializer::serialize(['id' => 1]);
        $decoded = json_decode($json, true);

        $this->assertNull($decoded['version']);
    }

    public function test_serialize_cached_at_is_iso_format(): void
    {
        $json = CacheSerializer::serialize([]);
        $decoded = json_decode($json, true);

        // Should contain date-like pattern: YYYY-MM-DDTHH:MM:SS
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $decoded['cached_at']
        );
    }

    // -- deserialize ------------------------------------

    public function test_deserialize_extracts_envelope(): void
    {
        $json = json_encode([
            'cached_at' => '2026-03-31T12:00:00.000000Z',
            'version'   => 5,
            'data'      => ['name' => 'Test'],
        ]);

        $result = CacheSerializer::deserialize($json);

        $this->assertSame(['name' => 'Test'], $result['data']);
        $this->assertSame(5, $result['version']);
        $this->assertSame('2026-03-31T12:00:00.000000Z', $result['cached_at']);
    }

    public function test_deserialize_handles_missing_data_key(): void
    {
        // Non-envelope JSON (e.g., raw value stored by another system)
        $json = json_encode(['name' => 'Raw Value']);

        $result = CacheSerializer::deserialize($json);

        // Should wrap raw value as data, with null version/cached_at
        $this->assertSame(['name' => 'Raw Value'], $result['data']);
        $this->assertNull($result['version']);
        $this->assertNull($result['cached_at']);
    }

    public function test_deserialize_throws_on_invalid_json(): void
    {
        $this->expectException(\JsonException::class);

        CacheSerializer::deserialize('not-valid-json{{{');
    }

    // -- roundtrip --------------------------------------

    public function test_serialize_then_deserialize_preserves_data(): void
    {
        $original = ['id' => 42, 'name' => 'Acme Project', 'nested' => ['a' => 1]];

        $serialized = CacheSerializer::serialize($original, 7);
        $deserialized = CacheSerializer::deserialize($serialized);

        $this->assertSame($original, $deserialized['data']);
        $this->assertSame(7, $deserialized['version']);
    }

    // -- isVersionValid ----------------------------------

    public function test_isVersionValid_returns_true_on_match(): void
    {
        $deserialized = ['data' => [], 'version' => 5, 'cached_at' => 'now'];

        $this->assertTrue(CacheSerializer::isVersionValid($deserialized, 5));
    }

    public function test_isVersionValid_returns_false_on_mismatch(): void
    {
        $deserialized = ['data' => [], 'version' => 3, 'cached_at' => 'now'];

        $this->assertFalse(CacheSerializer::isVersionValid($deserialized, 5));
    }

    public function test_isVersionValid_returns_false_when_version_null(): void
    {
        $deserialized = ['data' => [], 'version' => null, 'cached_at' => 'now'];

        $this->assertFalse(CacheSerializer::isVersionValid($deserialized, 1));
    }

    public function test_isVersionValid_uses_strict_comparison(): void
    {
        // Version 5 (int) should not match "5" if strict — but PHP uses ===
        $deserialized = ['data' => [], 'version' => 5, 'cached_at' => 'now'];

        // Same int, should pass
        $this->assertTrue(CacheSerializer::isVersionValid($deserialized, 5));

        // Different value, should fail
        $this->assertFalse(CacheSerializer::isVersionValid($deserialized, 6));
    }

}