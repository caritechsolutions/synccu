<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Rate-limiting middleware.
 *
 * Uses APCu when available, falling back to file-based storage.
 * Limits are configurable per-route via middleware parameters.
 *
 * Usage:
 *   RateLimitMiddleware::class            // uses default RATE_LIMIT_PER_MINUTE
 *   RateLimitMiddleware::class . ':30'    // 30 requests per minute
 */
final class RateLimitMiddleware
{
    private const WINDOW_SECONDS = 60;

    public function handle(Request $request, callable $next, string $maxRequests = ''): Response
    {
        $limit = $maxRequests !== ''
            ? (int) $maxRequests
            : (int) env('RATE_LIMIT_PER_MINUTE', 60);

        $identifier = $this->resolveIdentifier($request);
        $key        = 'rl:' . md5($identifier);

        $record = $this->getRecord($key);
        $now    = time();

        // Reset window if expired
        if ($record === null || ($now - $record['window_start']) >= self::WINDOW_SECONDS) {
            $record = [
                'window_start' => $now,
                'count'        => 0,
            ];
        }

        $record['count']++;

        $this->setRecord($key, $record, self::WINDOW_SECONDS);

        $remaining  = max(0, $limit - $record['count']);
        $retryAfter = self::WINDOW_SECONDS - ($now - $record['window_start']);

        if ($record['count'] > $limit) {
            return Response::json(
                ['error' => 'Too many requests. Please try again later.'],
                429,
                [
                    'Retry-After'            => (string) max(1, $retryAfter),
                    'X-RateLimit-Limit'      => (string) $limit,
                    'X-RateLimit-Remaining'  => '0',
                    'X-RateLimit-Reset'      => (string) ($record['window_start'] + self::WINDOW_SECONDS),
                ],
            );
        }

        $response = $next($request);

        // Attach rate-limit headers to successful responses
        return Response::json(
            $response->getBody(),
            $response->getStatus(),
            array_merge($response->getHeaders(), [
                'X-RateLimit-Limit'     => (string) $limit,
                'X-RateLimit-Remaining' => (string) $remaining,
                'X-RateLimit-Reset'     => (string) ($record['window_start'] + self::WINDOW_SECONDS),
            ]),
        );
    }

    // ------------------------------------------------------------------
    // Identifier Resolution
    // ------------------------------------------------------------------

    private function resolveIdentifier(Request $request): string
    {
        // Prefer authenticated user id, fall back to IP
        $userId = $request->getAttribute('user_id');
        if ($userId !== null) {
            return "user:{$userId}";
        }
        return "ip:{$request->ip()}";
    }

    // ------------------------------------------------------------------
    // Storage Abstraction
    // ------------------------------------------------------------------

    private function getRecord(string $key): ?array
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $value = apcu_fetch($key, $success);
            return $success ? $value : null;
        }

        return $this->fileGet($key);
    }

    private function setRecord(string $key, array $record, int $ttl): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_store($key, $record, $ttl);
            return;
        }

        $this->fileSet($key, $record, $ttl);
    }

    // ------------------------------------------------------------------
    // File-Based Fallback
    // ------------------------------------------------------------------

    private function storagePath(): string
    {
        $dir = sys_get_temp_dir() . '/synccu_rate_limit';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private function fileGet(string $key): ?array
    {
        $file = $this->storagePath() . '/' . md5($key) . '.json';
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }

        // Check TTL
        if (isset($data['__expires']) && $data['__expires'] < time()) {
            @unlink($file);
            return null;
        }

        unset($data['__expires']);
        return $data;
    }

    private function fileSet(string $key, array $record, int $ttl): void
    {
        $file = $this->storagePath() . '/' . md5($key) . '.json';
        $record['__expires'] = time() + $ttl;
        file_put_contents($file, json_encode($record), LOCK_EX);
    }
}
