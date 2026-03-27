<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Multi-tenant context middleware.
 *
 * Resolves the tenant from (in priority order):
 *   1. X-Tenant-ID header
 *   2. Subdomain of the request host
 *   3. tenant_id from the authenticated user's JWT claims
 *
 * Once resolved, the tenant context is set on the Database singleton
 * so all scoped queries automatically include the tenant_id predicate.
 */
final class TenantMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $tenantId = $this->resolve($request);

        if ($tenantId === null) {
            return Response::error('Tenant could not be resolved', 400);
        }

        // Validate the tenant exists and is active
        $db = Database::getInstance();
        $tenant = $db->fetchOne(
            'SELECT id, slug, name, status FROM tenants WHERE id = ? LIMIT 1',
            [$tenantId],
        );

        if ($tenant === null) {
            return Response::error('Tenant not found', 404);
        }

        if (($tenant['status'] ?? '') !== 'active') {
            return Response::error('Tenant account is suspended', 403);
        }

        // Set tenant context
        $db->setTenantId($tenantId);
        $request->setAttribute('tenant_id', $tenantId);
        $request->setAttribute('tenant', $tenant);

        return $next($request);
    }

    // ------------------------------------------------------------------

    private function resolve(Request $request): ?string
    {
        // 1. Explicit header
        $headerTenant = $request->header('x-tenant-id');
        if ($headerTenant !== null && $headerTenant !== '') {
            return $headerTenant;
        }

        // 2. Subdomain
        $host = $request->header('host', '');
        $subdomain = $this->extractSubdomain($host);
        if ($subdomain !== null) {
            return $this->resolveTenantBySlug($subdomain);
        }

        // 3. JWT claim (set by AuthMiddleware)
        $jwtTenant = $request->getAttribute('tenant_id');
        if ($jwtTenant !== null) {
            return $jwtTenant;
        }

        return null;
    }

    /**
     * Extract a subdomain from the host, ignoring common prefixes like "www".
     */
    private function extractSubdomain(string $host): ?string
    {
        // Remove port
        $host = strtolower(explode(':', $host)[0]);

        $parts = explode('.', $host);

        // Need at least 3 parts (sub.domain.tld) to have a subdomain
        if (count($parts) < 3) {
            return null;
        }

        $subdomain = $parts[0];

        // Ignore common non-tenant prefixes
        if (in_array($subdomain, ['www', 'api', 'app', 'admin', 'mail'], true)) {
            return null;
        }

        return $subdomain;
    }

    /**
     * Look up a tenant ID by its URL slug.
     */
    private function resolveTenantBySlug(string $slug): ?string
    {
        $db  = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT id FROM tenants WHERE slug = ? AND status = ? LIMIT 1',
            [$slug, 'active'],
        );

        return $row['id'] ?? null;
    }
}
