<?php

namespace SynergyERP\Shared\Tests\Cache;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Cache\CacheService;
use SynergyERP\Shared\Cache\CacheSerializer;

class CacheServiceTest extends TestCase
{
    private CacheService $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new CacheService(
            baseUrl: 'http://fake-cache-api:1001',
            cacheName: 'global',
            timeout: 2
        );
    }

    // ── get() ──────────────────────────────────────────────

    public function test_get_returns_deserialized_data_on_hit(): void
    {
        $envelope = CacheSerializer::serialize(['name' => 'Test'], 1);

        Http::fake([
            '*/global-cache/global/get' => Http::response([
                'found' => true,
                'value' => $envelope,
            ]),
        ]);

        $result = $this->cache->get('tenant:rrglass|project:42:1');

        $this->assertSame(['name' => 'Test'], $result['data']);
        $this->assertSame(1, $result['version']);
    }

    public function test_get_returns_null_on_cache_miss(): void
    {
        Http::fake([
            '*/global-cache/global/get' => Http::response([
                'found' => false,
            ]),
        ]);

        $this->assertNull($this->cache->get('tenant:rrglass|project:999:1'));
    }

    public function test_get_returns_null_on_http_failure(): void
    {
        Http::fake([
            '*/global-cache/global/get' => Http::response([], 500),
        ]);

        $this->assertNull($this->cache->get('any-key'));
    }

    public function test_get_returns_null_on_connection_error(): void
    {
        Http::fake([
            '*/global-cache/global/get' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $this->assertNull($this->cache->get('any-key'));
    }

    // ── set() ──────────────────────────────────────────────

    public function test_set_returns_true_on_success(): void
    {
        Http::fake([
            '*/global-cache/global/set' => Http::response(['success' => true]),
        ]);

        $result = $this->cache->set('tenant:rrglass|project:42:1', ['name' => 'Test'], 1, 3600);

        $this->assertTrue($result);
    }

    public function test_set_sends_ttl_when_provided(): void
    {
        Http::fake([
            '*/global-cache/global/set' => Http::response(['success' => true]),
        ]);

        $this->cache->set('key', ['data'], 1, 3600);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return isset($body['ttl']) && $body['ttl'] === 3600;
        });
    }

    public function test_set_omits_ttl_when_null(): void
    {
        Http::fake([
            '*/global-cache/global/set' => Http::response(['success' => true]),
        ]);

        $this->cache->set('key', ['data'], 1);

        Http::assertSent(function ($request) {
            return !isset($request->data()['ttl']);
        });
    }

    public function test_set_returns_false_on_failure(): void
    {
        Http::fake([
            '*/global-cache/global/set' => Http::response([], 500),
        ]);

        $this->assertFalse($this->cache->set('key', ['data']));
    }

    public function test_set_returns_false_on_connection_error(): void
    {
        Http::fake([
            '*/global-cache/global/set' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $this->assertFalse($this->cache->set('key', ['data']));
    }

    // ── delete() ───────────────────────────────────────────

    public function test_delete_returns_true_on_success(): void
    {
        Http::fake([
            '*/global-cache/global/delete' => Http::response(['success' => true]),
        ]);

        $this->assertTrue($this->cache->delete('some-key'));
    }

    public function test_delete_returns_false_on_failure(): void
    {
        Http::fake([
            '*/global-cache/global/delete' => Http::response([], 500),
        ]);

        $this->assertFalse($this->cache->delete('some-key'));
    }

    // ── increment() ────────────────────────────────────────

    public function test_increment_returns_new_value(): void
    {
        Http::fake([
            '*/global-cache/global/increment' => Http::response(['value' => 5]),
        ]);

        $this->assertSame(5, $this->cache->increment('version-key'));
    }

    public function test_increment_returns_null_on_failure(): void
    {
        Http::fake([
            '*/global-cache/global/increment' => Http::response([], 500),
        ]);

        $this->assertNull($this->cache->increment('version-key'));
    }

    public function test_increment_returns_null_on_connection_error(): void
    {
        Http::fake([
            '*/global-cache/global/increment' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            },
        ]);

        $this->assertNull($this->cache->increment('version-key'));
    }

    // ── getVersion() ───────────────────────────────────────

    public function test_getVersion_returns_version(): void
    {
        Http::fake([
            '*/global-cache/global/version-get' => Http::response(['version' => 3]),
        ]);

        $this->assertSame(3, $this->cache->getVersion('version-key'));
    }

    public function test_getVersion_returns_zero_on_failure(): void
    {
        Http::fake([
            '*/global-cache/global/version-get' => Http::response([], 500),
        ]);

        $this->assertSame(0, $this->cache->getVersion('version-key'));
    }

    public function test_getVersion_returns_zero_on_connection_error(): void
    {
        Http::fake([
            '*/global-cache/global/version-get' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            },
        ]);

        $this->assertSame(0, $this->cache->getVersion('version-key'));
    }

    // ── multiGet() ─────────────────────────────────────────

    public function test_multiGet_returns_deserialized_results(): void
    {
        $envelope = CacheSerializer::serialize(['name' => 'Test'], 1);

        Http::fake([
            '*/global-cache/global/multi-get' => Http::response([
                'results' => [
                    'key-1' => $envelope,
                    'key-2' => null,
                ],
            ]),
        ]);

        $results = $this->cache->multiGet(['key-1', 'key-2']);

        $this->assertSame(['name' => 'Test'], $results['key-1']['data']);
        $this->assertNull($results['key-2']);
    }

    public function test_multiGet_returns_all_nulls_on_failure(): void
    {
        Http::fake([
            '*/global-cache/global/multi-get' => Http::response([], 500),
        ]);

        $results = $this->cache->multiGet(['key-1', 'key-2']);

        $this->assertNull($results['key-1']);
        $this->assertNull($results['key-2']);
    }

    // ── exists() ───────────────────────────────────────────

    public function test_exists_returns_true_when_key_exists(): void
    {
        Http::fake([
            '*/global-cache/global/exists' => Http::response(['success' => true]),
        ]);

        $this->assertTrue($this->cache->exists('some-key'));
    }

    public function test_exists_returns_false_when_key_missing(): void
    {
        Http::fake([
            '*/global-cache/global/exists' => Http::response(['success' => false]),
        ]);

        $this->assertFalse($this->cache->exists('some-key'));
    }

    // ── batchDelete() ──────────────────────────────────────

    public function test_batchDelete_returns_deleted_count(): void
    {
        Http::fake([
            '*/global-cache/global/batch-delete' => Http::response(['deleted' => 3]),
        ]);

        $this->assertSame(3, $this->cache->batchDelete(['k1', 'k2', 'k3']));
    }

    public function test_batchDelete_returns_zero_on_failure(): void
    {
        Http::fake([
            '*/global-cache/global/batch-delete' => Http::response([], 500),
        ]);

        $this->assertSame(0, $this->cache->batchDelete(['k1']));
    }
}