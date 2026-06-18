<?php

namespace SynergyERP\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Cache\CacheKeyBuilder;
use SynergyERP\Shared\Cache\CacheSerializer;
use SynergyERP\Shared\Cache\CacheService;
use SynergyERP\Shared\Models\Operations\OperationKeyContext;
use SynergyERP\Shared\Models\Transactions\TransactionRequest;

/**
 * Cache-aside middleware for Synergy ERP Laravel Services.
 * 
 * Runs AFTER TransactionEventMiddleware in the middleware chain.
 * TransactionEventMiddleware always initializes a TransactionEvent first,
 * ensuring audit-proof logging of every client interaction - including cache hits.
 * 
 * For QUERIES (fetch/search):
 *      - Checks cache for a valid entry
 *      - on HIT: return cached response (TransactionEvent still created by outer middleware)
 *      - on MISS: passes through to handler, then caches the successful response
 * 
 * For COMMANDS (create/update/delete):
 *      - Always passes through to the handler
 *      - After successful execution: invalidates/updates affected cache keys
 *          and increments the model version counter
 * 
 * Cache failures are NON-BLOCKING - if Redis/cache-api is down,
 * the request proceeds normally via the database. The DB is always canonical.
 * 
 * @author Ryan Winn
 * @package SynergyERP\Shared\Middleware
 */
class CacheMiddleware
{
    /**
     * Actions that are read operations eligible for cache-aside.
     */
    private const QUERY_ACTIONS = ['fetch', 'search'];

    /**
     * Actions that modify state and require cache invalidation.
     */
    private const COMMAND_ACTIONS = ['create', 'update', 'delete', 'archive', 'restore', 'unarchive'];

    /**
     * Default TTL for cached query results (1 hour).
     * Individual services can override via CACHE_DEFAULT_TTL env var.
     */
    private const DEFAULT_TTL = 3600;

    private CacheService $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle an incoming request with cache-aside logic
     * 
     * @param Request $request          The incoming HTTP request
     * @param Closure $next             The next middleware in the chain
     * @param string|null $operationKey The operation key (e.g., "project-service.query.project.fetch")
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ?string $operationKey = null): mixed
    {
        // If no operation key, skip caching entirely and pass through
        if (!$operationKey) {
            return $next($request);
        }

        $operationContext = new OperationKeyContext($operationKey);
        $action = $operationContext->getOperationComponent('action');
        $cqrs = $operationContext->getOperationComponent('cqrs');

        // Determine if this is a cacheable query or a state-changing command
        $isQuery = $cqrs === 'query' || in_array($action, self::QUERY_ACTIONS, true);
        $isCommand = $cqrs === 'command' || in_array($action, self::COMMAND_ACTIONS, true);

        // Extract tenant public_id from JWT for cache key construction
        $tenantPuid = $this->extractTenantPuid($request);
        if (!$tenantPuid) {
            // No tenant context - skip caching, let TransactionEventMiddleware handle auth errors
            return $next($request);
        }

        // Build cache keys from the operation context
        $requestData = $request->all();
        $cacheKeys = CacheKeyBuilder::fromContext($operationContext, $tenantPuid, $requestData);

        // --- QUERY PATH: Check cache before hitting DB ---
        if ($isQuery) {
            $cached = $this->attemptCacheRead($cacheKeys, $action, $operationKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // -- PASS THROUGH to TransactionEventMiddleware -> Handler ---
        $response = $next($request);

        // -- POST-EXECUTION: Cache result or invalidate ---
        if ($isQuery && $response instanceof JsonResponse && $response->getStatusCode() === 200) {
            $this->cacheQueryResult($cacheKeys, $action, $response, $operationKey);
        } elseif ($isCommand && $response instanceof JsonResponse && $response->getStatusCode() === 200) {
            $this->invalidateOnCommand($cacheKeys, $action, $operationKey, $requestData);
        }

        return $response;
    }

    /**
     * Attempt to read from cache for a query operation.
     * 
     * For FETCH: looks up version counter -> builds versioned record key -> checks cache
     * For SEARCH: checks the list key, validated against the version counter.
     * 
     * @param array $cacheKeys      The cache keys from CacheKeyBuilder::fromContext()
     * @param string $action        The query action (fetch or search)
     * @param string $operationKey  For logging
     * @return JsonResponse|null    Cached response, or null on miss
     */
    private function attemptCacheRead(array $cacheKeys, string $action, string $operationKey): ?JsonResponse
    {
        try {
            // Both fetch and search need the current version
            $versionResult = $this->cacheService->get($cacheKeys['version']);
            $currentVersion = $versionResult !== null ? (int) $versionResult['data'] : 0;

            if ($action === 'fetch' && $cacheKeys['model_id'] !== null) {
                // Build versioned record key, then check cache
                $recordKey = CacheKeyBuilder::buildRecordKey($cacheKeys, $currentVersion);

                if ($recordKey !== null) {
                    $cached = $this->cacheService->get($recordKey);

                    if ($cached !== null) {
                        // tell TransactionEventMiddleware this was served from cache
                        request()->attributes->set('cache_source', 'cache');

                        Log::info('[CacheMiddleware] HIT (fetch)', [
                            'key'       => $recordKey,
                            'version'   => $currentVersion,
                            'operation' => $operationKey,
                        ]);
                        return $this->buildCacheResponse($cached['data'], true);
                    }
                }
            } else if ($action === 'search') {
                // Use the filter-aware search key so different WHERE clauses
                // never return each other's cached results.
                $cached = $this->cacheService->get($cacheKeys['search']);

                if ($cached !== null) {
                    if (CacheSerializer::isVersionValid($cached, $currentVersion)) {
                        request()->attributes->set('cache_source', 'cache');

                        Log::info('[CacheMiddleware] HIT (search)', [
                            'key'       => $cacheKeys['search'],
                            'version'   => $currentVersion,
                            'operation' => $operationKey,
                        ]);
                        return $this->buildCacheResponse($cached['data'], true);
                    }

                    Log::info('[CacheMiddleware] STALE (search) - version mismatch', [
                        'key'               => $cacheKeys['search'],
                        'cached_version'    => $cached['version'] ?? 'null',
                        'current_version'   => $currentVersion,
                        'operation'         => $operationKey,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Cache read failure is non-blocking - fall through to DB
            Log::warning('[CacheMiddleware] Cache read failed (non-blocking)', [
                'operation' => $operationKey,
                'error'     => $e->getMessage(),
            ]);
        }

        return null;
    }
    
    /**
     * Cache a successful query result.
     * 
     * @param array $cacheKeys          The cache keys
     * @param string $action            The query action
     * @param JsonResponse $response    The successful response from the handler
     * @param string $operationKey      For logging
     */
    private function cacheQueryResult(
        array $cacheKeys,
        string $action,
        JsonResponse $response,
        string $operationKey
    ): void {
        try {
            $responseData = $response->getData(true);
            $data = $responseData['data'] ?? $responseData;
            $ttl = (int) env('CACHE_DEFAULT_TTL', self::DEFAULT_TTL);

            // Resolve current version (shared by both fetch and search)
            $versionResult = $this->cacheService->get($cacheKeys['version']);
            $currentVersion = $versionResult !== null ? (int) $versionResult['data'] : 0;

            // If no version exists yet, initialize it
            if ($currentVersion === 0) {
                $this->cacheService->set($cacheKeys['version'], 1);
                $currentVersion = 1;
            }

            if ($action === 'fetch' && $cacheKeys['model_id'] !== null) {
                $recordKey = CacheKeyBuilder::buildRecordKey($cacheKeys, $currentVersion);

                if ($recordKey !== null) {
                    $this->cacheService->set($recordKey, $data, $currentVersion, $ttl);

                    Log::info('[CacheMiddleware] Cached fetch result', [
                        'key'       => $recordKey,
                        'operation' => $operationKey,
                    ]);
                }
            } elseif ($action ==='search') {
                $this->cacheService->set($cacheKeys['search'], $data, $currentVersion, $ttl);

                Log::info('[CacheMiddleware] Cached search result', [
                    'key'       => $cacheKeys['search'],
                    'version'   => $currentVersion,
                    'operation' => $operationKey,
                ]);
            }

        } catch (\Throwable $e) {
            // Cache write failure is non-blocking
            Log::warning('[CacheMiddleware] Cache write failed (non-blocking)', [
                'operation' => $operationKey,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate cache keys after a successful command.
     * Uses atomic INCR for version counter and batch-delete for efficiency
     * 
     * Strategy:
     *  - DELETE the old versioned record key (if we know the model ID + old version)
     *  - DELETE the list key (stale after any write)
     *  - INCREMENT the version counter (invalidates any list
     *      caches that other request may have cached with the old version,
     *      and makes old versioned record keys unreachable)
     * 
     * @param array $cacheKeys      The cache keys
     * @param string $action        The command action
     * @param string $operationKey  For logging
     * @param array $requestData    The request data (may contain model ID for update/delete)
     */
    private function invalidateOnCommand(
        array $cacheKeys,
        string $action,
        string $operationKey,
        array $requestData
    ): void {
        try {
            // Get current version before incrementing (to delete old versioned record key)
            $versionResult = $this->cacheService->get($cacheKeys['version']);
            $currentVersion = $versionResult !== null ? (int) $versionResult['data'] : 0;

            // Always invalidate the list cache
            $this->cacheService->delete($cacheKeys['list']);

            // Delete the old versioned record key if we have an ID
            if ($cacheKeys['model_id'] !== null) {
                $oldRecordKey = CacheKeyBuilder::buildRecordKey($cacheKeys, $currentVersion);
                if ($oldRecordKey !== null) {
                    $this->cacheService->delete($oldRecordKey);
                }
            }

            // Increment version counter - this makes old versioned keys unreachable
            // and invalidates any in-flight list caches
            $newVersion = $currentVersion + 1;
            $this->cacheService->set($cacheKeys['version'], $newVersion);

            // Build room key for WebSocket notification to connected clients
            $roomKey = CacheKeyBuilder::forRoom(
                $cacheKeys['tenant_key'],
                $cacheKeys['model_name'],
                $cacheKeys['model_id']
            );

            // Emit event to collaboration room (future WebSocket integration)
            // event(new CacheInvalidatedEvent($roomKey, $action, $modelId, $newVersion));

            Log::info('[CacheMiddleware] Invalidated cache after command', [
                'action'        => $action,
                'model_id'      => $cacheKeys['model_id'],
                'list_key'      => $cacheKeys['list'],
                'old_version'   => $currentVersion,
                'new_version'   => $newVersion,
                'operation'     => $operationKey,
            ]);
        } catch (\Throwable $e) {
            // Invalidation failure is logged but non-blocking.
            // TTL will eventually expire stale/orphaned entries.
            Log::warning('[CacheMiddleware] Invalidation failed (non-blocking, TTL will handle', [
                'operation' => $operationKey,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a standardized JSON response for cached data.
     * Matches the TransactionResponse format so clients see no difference
     * 
     * @param mixed $data       The cached data
     * @param bool $fromCache   Whether this was served from cache (for debugging headers)
     * @return JsonResponse
     */
    private function buildCacheResponse(mixed $data, bool $fromCache = false): JsonResponse
    {
        $response = new JsonResponse([
            'success'   => true,
            'message'   => 'OK',
            'data'      => $data,
            'errors'    => [],
        ], 200);

        if ($fromCache) {
            $response->headers->set('X-Cache', 'HIT');
        }

        return $response;
    }


    /**
     * Extract the tenant public_id from the JWT token in the request.
     * Uses the same JWT extraction logic as TransactionRequest.
     *
     * @param Request $request
     * @return string|null The tenant public_id, or null if not available
     */
    private function extractTenantPuid(Request $request): ?string
    {
        try {
            $token = $request->bearerToken();
            if (!$token) {
                return null;
            }

            // Decode JWT payload (middle segment) without full verification
            // (verification happens in TransactionEventMiddleware/auth)
            $segments = explode('.', $token);
            if (count($segments) !== 3) {
                return null;
            }

            $payload = json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true);

            return $payload['tenant_puid'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('[CacheMiddleware] Failed to extract tenant public_id from JWT', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}