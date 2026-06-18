<?php

namespace SynergyERP\Shared\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mints ULIDs by calling the auth-service `/ulid/create` endpoint, which is
 * the system-wide source of truth for record public_ids. The contract layer
 * (BaseCommandContract::afterValidation) calls this on every create so that
 * public_ids are generated centrally rather than locally per service.
 *
 * Resilience: if the auth-service is unreachable (offline tests, dev without
 * the gateway up), this falls back to a locally-generated ULID and logs a
 * warning. The caller cannot tell the difference — both paths return a
 * valid 26-char Crockford-base32 ULID — but ops can grep the warning when
 * cross-service ULIDs unexpectedly diverge.
 */
final class AuthServiceUlidClient
{
    /**
     * Mint a fresh ULID. Always returns a valid 26-char ULID string —
     * either from the auth-service or, on failure, from a local fallback.
     */
    public static function mint(): string
    {
        $base    = rtrim((string) env('AUTH_SERVICE_URL', 'http://auth-service:9001/api'), '/');
        $url     = "{$base}/ulid/create";
        $timeout = (int) env('AUTH_SERVICE_TIMEOUT', 2);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($url, ['ulid' => null]);

            if ($response->successful()) {
                $ulid = self::extractUlid($response->json());
                if ($ulid !== null) {
                    return $ulid;
                }
                Log::warning('AuthServiceUlidClient: response missing ulid', [
                    'url'    => $url,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            } else {
                Log::warning('AuthServiceUlidClient: non-2xx response', [
                    'url'    => $url,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AuthServiceUlidClient: request failed, using local fallback', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return (string) Str::ulid();
    }

    /**
     * Pull a 26-char ULID out of the auth-service response. The endpoint
     * wraps the saved row in a few different envelopes depending on which
     * BaseController path runs (queued vs sync), so we probe the common
     * shapes rather than hardcoding one.
     */
    private static function extractUlid(mixed $payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        $candidates = [
            $payload['ulid']                  ?? null,
            $payload['data']['ulid']          ?? null,
            $payload['data'][0]['ulid']       ?? null,
            $payload['result']['ulid']        ?? null,
            $payload['result'][0]['ulid']     ?? null,
            $payload['response']['ulid']      ?? null,
            $payload['data']['public_id']     ?? null,
            $payload['data'][0]['public_id']  ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $candidate) === 1) {
                return $candidate;
            }
        }
        return null;
    }
}
