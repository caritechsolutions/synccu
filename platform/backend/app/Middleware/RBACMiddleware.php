<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Role-Based Access Control middleware.
 *
 * Checks the authenticated user's role against the roles required
 * for the current route. Must run after AuthMiddleware.
 *
 * Usage in route definitions:
 *   RBACMiddleware::class . ':admin,manager'
 */
final class RBACMiddleware
{
    /**
     * Handle the request.
     *
     * @param Request  $request
     * @param callable $next
     * @param string   ...$allowedRoles  Roles permitted to access this route.
     * @return Response
     */
    public function handle(Request $request, callable $next, string ...$allowedRoles): Response
    {
        $userRole = $request->getAttribute('role');

        if ($userRole === null) {
            return Response::error('Authentication required', 401);
        }

        if (empty($allowedRoles)) {
            // No specific roles required; any authenticated user may proceed.
            return $next($request);
        }

        if (!in_array($userRole, $allowedRoles, true)) {
            return Response::error('Insufficient permissions', 403);
        }

        return $next($request);
    }
}
