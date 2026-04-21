<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\NetworkService;

final class NodeAuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $apiKey = $this->extractApiKey($request);

        if ($apiKey === null) {
            return Response::error('Node API key required', 401);
        }

        $tenantId = $request->getAttribute('tenant_id');
        if (!$tenantId) {
            return Response::error('Tenant context required', 400);
        }

        $network = new NetworkService();
        $node = $network->authenticateNode($tenantId, $apiKey);

        if ($node === null) {
            return Response::error('Invalid node API key', 401);
        }

        $request->setAttribute('authenticated_node', $node);
        $request->setAttribute('node_id', $node['id']);

        return $next($request);
    }

    private function extractApiKey(Request $request): ?string
    {
        $header = $request->header('x-node-api-key');
        if ($header !== null && $header !== '') {
            return $header;
        }

        $auth = $request->header('authorization', '');
        if (str_starts_with($auth, 'Node ')) {
            return substr($auth, 5);
        }

        return null;
    }
}
