<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * JWT authentication middleware.
 *
 * Extracts and validates the bearer token, decodes the payload, and
 * attaches the authenticated user context to the request.
 */
final class AuthMiddleware
{
    /**
     * Handle the request.
     *
     * @param Request  $request
     * @param callable $next
     * @return Response
     */
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return Response::error('Authentication required', 401);
        }

        $payload = $this->verifyToken($token);

        if ($payload === null) {
            return Response::error('Invalid or expired token', 401);
        }

        // Attach decoded claims to the request
        $request->setAttribute('user_id', $payload['sub'] ?? null);
        $request->setAttribute('tenant_id', $payload['tenant_id'] ?? null);
        $request->setAttribute('role', $payload['role'] ?? 'member');
        $request->setAttribute('jwt_payload', $payload);

        return $next($request);
    }

    /**
     * Verify and decode a JWT token.
     *
     * @return array|null Decoded payload or null on failure.
     */
    private function verifyToken(string $token): ?array
    {
        $secret = env('JWT_SECRET', '');
        if ($secret === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Verify signature
        $expectedSig = $this->base64UrlEncode(
            hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true)
        );

        if (!hash_equals($expectedSig, $signatureB64)) {
            return null;
        }

        // Decode header
        $header = json_decode($this->base64UrlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiry
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        // Check not-before
        if (isset($payload['nbf']) && $payload['nbf'] > time()) {
            return null;
        }

        return $payload;
    }

    // ------------------------------------------------------------------
    // JWT Encoding Utilities (also used by AuthService)
    // ------------------------------------------------------------------

    /**
     * Create a signed JWT token.
     */
    public static function createToken(array $payload): string
    {
        $secret = env('JWT_SECRET', '');

        $header = self::base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ]));

        $payload['iat'] = $payload['iat'] ?? time();
        $payload['exp'] = $payload['exp'] ?? time() + (int) env('JWT_EXPIRY', 3600);

        $payloadB64 = self::base64UrlEncode(json_encode($payload));

        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payloadB64}", $secret, true)
        );

        return "{$header}.{$payloadB64}.{$signature}";
    }

    /**
     * Create a refresh token with a longer expiry.
     */
    public static function createRefreshToken(array $payload): string
    {
        $payload['exp'] = time() + (int) env('JWT_REFRESH_EXPIRY', 604800);
        $payload['type'] = 'refresh';
        return self::createToken($payload);
    }

    /**
     * Decode a token without verification (use only after verify).
     */
    public static function decodePayload(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        return json_decode(self::base64UrlDecode($parts[1]), true);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
