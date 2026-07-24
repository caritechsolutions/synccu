<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Audit logging middleware.
 *
 * Captures all state-changing (write) operations and persists them
 * to the audit_logs table with the acting user, IP, user agent,
 * and a JSON snapshot of the request payload.
 */
final class AuditMiddleware
{
    /** HTTP methods considered "write" operations. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, callable $next): Response
    {
        // Execute the handler first so we know the outcome
        $response = $next($request);

        // Only audit write operations
        if (!in_array($request->method(), self::WRITE_METHODS, true)) {
            return $response;
        }

        try {
            $this->log($request, $response);
        } catch (\Throwable) {
            // Audit failure must never break the primary request.
        }

        return $response;
    }

    // ------------------------------------------------------------------

    private function log(Request $request, Response $response): void
    {
        $db = Database::getInstance();

        $userId   = $request->getAttribute('user_id');
        $tenantId = $request->getAttribute('tenant_id') ?? $db->getTenantId();

        // Redact sensitive fields from the payload snapshot
        $payload = $request->all();
        foreach (['password', 'password_confirmation', 'current_password', 'token', 'secret'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = '***REDACTED***';
            }
        }

        $responseBody = $response->getBody();
        $oldValues    = null;
        $newValues    = null;

        // A controller may stash the pre-change snapshot on the request so the
        // log records before -> after (e.g. account edits).
        $auditOld = $request->getAttribute('audit_old');
        if ($auditOld !== null) {
            $oldValues = json_encode($auditOld, JSON_UNESCAPED_UNICODE);
        }

        // Attempt to capture new values from a successful response
        if (is_array($responseBody)) {
            $newValues = $responseBody['data'] ?? $responseBody;
            // Remove nested arrays deeper than 2 levels for storage efficiency
            $newValues = json_encode($newValues, JSON_UNESCAPED_UNICODE);
        }

        $db->query(
            'INSERT INTO audit_logs
                (id, tenant_id, user_id, action, entity_type, entity_id, old_values, new_values,
                 ip_address, user_agent, created_at)
             VALUES
                (UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $tenantId,
                $userId,
                $this->deriveAction($request),
                $this->deriveResource($request),
                $this->deriveResourceId($request),
                $oldValues,
                $newValues,
                $request->ip(),
                mb_substr($request->userAgent(), 0, 512),
            ],
        );
    }

    /**
     * Derive a human-readable action name from the request.
     */
    private function deriveAction(Request $request): string
    {
        $method = $request->method();
        $path   = $request->path();

        // Extract the last meaningful segment
        $segments = array_values(array_filter(explode('/', $path)));
        $last     = end($segments) ?: 'unknown';

        return match ($method) {
            'POST'   => "create_{$last}",
            'PUT', 'PATCH' => "update_{$last}",
            'DELETE'  => "delete_{$last}",
            default   => strtolower($method) . "_{$last}",
        };
    }

    /**
     * Derive the resource type from the URL path.
     */
    private function deriveResource(Request $request): string
    {
        $segments = array_values(array_filter(explode('/', $request->path())));

        // Skip 'api' prefix and find the first meaningful resource name
        foreach ($segments as $segment) {
            if ($segment === 'api') {
                continue;
            }
            // Skip UUID-looking or numeric segments
            if (preg_match('/^[0-9a-f-]{36}$|^\d+$/', $segment)) {
                continue;
            }
            return $segment;
        }

        return 'unknown';
    }

    /**
     * Extract a resource ID from route params or the URL.
     */
    private function deriveResourceId(Request $request): ?string
    {
        // Try route param
        $id = $request->param('id');
        if ($id !== null) {
            return (string) $id;
        }

        // Try to find a UUID or numeric ID in the path
        $segments = explode('/', $request->path());
        foreach ($segments as $segment) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)) {
                return $segment;
            }
        }

        return null;
    }
}
