<?php

namespace SynergyERP\Shared\Services;

use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

/**
 * Helper class for JWT token operations.
 */
class JwtHelper
{
    /**
     * Extract JWT token from request.
     *
     * @param Request $request
     * @return string|null
     */
    public static function getEncryptedToken(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        
        if (!$authHeader) {
            $cookieToken = $request->cookie('token_verifier');
            return $cookieToken ?: null;
        }
        
        $parts = explode(' ', $authHeader);
        
        if (count($parts) !== 2 || $parts[0] !== 'Bearer') {
            return null;
        }
        
        return $parts[1];
    }
    
    /**
     * Extract payload from JWT token.
     *
     * @param string $token
     * @param string|null $secret
     * @return array
     * @throws Exception
     */
    public static function getDecryptedPayload(string $token, ?string $secret = null): array
    {
        try {

            // import jwt secret from env
            $jwtSecret = $secret ?: env('JWT_SECRET');
            if ($jwtSecret === null) {
                throw new Exception('JWT_SECRET environment variable is not configured');
            }

            // Use provided secret or fallback to default development secret
            $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
            return (array) $decoded;
        } catch (Exception $e) {
            throw new Exception('Invalid token: ' . $e->getMessage());
        }
    }
}
