<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\NetworkService;

final class NetworkController
{
    private NetworkService $network;

    public function __construct()
    {
        $this->network = new NetworkService();
    }

    // ------------------------------------------------------------------
    // Dashboard / Stats
    // ------------------------------------------------------------------

    public function stats(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $stats = $this->network->getNetworkStats($tenantId);
        return Response::ok($stats);
    }

    // ------------------------------------------------------------------
    // Node CRUD
    // ------------------------------------------------------------------

    public function listNodes(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $filters = [
            'status' => $request->query('status'),
            'search' => $request->query('search'),
        ];

        $nodes = $this->network->listNodes($tenantId, array_filter($filters));
        return Response::ok($nodes);
    }

    public function showNode(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $nodeId = $request->getAttribute('id');
        $node = $this->network->getNode($tenantId, $nodeId);

        if (!$node) {
            return Response::error('Node not found', 404);
        }
        return Response::ok($node);
    }

    public function registerNode(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $validator = new Validator($request->all(), [
            'node_code'            => 'required|string|max:20',
            'name'                 => 'required|string|max:255',
            'endpoint_url'         => 'required|string|max:512',
            'is_router'            => 'nullable|boolean',
            'outbound_api_key'     => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();
        $apiKey = $this->network->generateApiKey();

        try {
            $result = $this->network->registerNode(
                $tenantId,
                $data['node_code'],
                $data['name'],
                $data['endpoint_url'],
                $apiKey,
                !empty($data['is_router']),
                $data['outbound_api_key'] ?? null,
            );

            $result['api_key'] = $apiKey;

            return Response::created($result, 'Node registered. Save the API key — it will not be shown again.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function updateNode(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $nodeId = $request->getAttribute('id');

        $validator = new Validator($request->all(), [
            'name'                 => 'nullable|string|max:255',
            'endpoint_url'         => 'nullable|string|max:512',
            'is_router'            => 'nullable|boolean',
            'status'               => 'nullable|string|in:active,inactive,pending',
            'outbound_api_key'     => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $node = $this->network->updateNode($tenantId, $nodeId, $validator->validated());
            return Response::ok($node, 'Node updated.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function deleteNode(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $nodeId = $request->getAttribute('id');

        try {
            $this->network->deleteNode($tenantId, $nodeId);
            return Response::ok(null, 'Node deleted.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function activateNode(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $nodeId = $request->getAttribute('id');

        try {
            $node = $this->network->activateNode($tenantId, $nodeId);
            return Response::ok($node, 'Node activated.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function regenerateApiKey(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $nodeId = $request->getAttribute('id');

        $apiKey = $this->network->generateApiKey();

        try {
            $this->network->updateNode($tenantId, $nodeId, ['api_key' => $apiKey]);
            return Response::ok(['api_key' => $apiKey], 'API key regenerated. Save it — it will not be shown again.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // ------------------------------------------------------------------
    // Network Transfers (admin view)
    // ------------------------------------------------------------------

    public function listTransfers(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $page = max(1, (int)($request->query('page') ?? 1));
        $perPage = min(100, max(1, (int)($request->query('per_page') ?? 25)));

        $filters = array_filter([
            'direction'      => $request->query('direction'),
            'status'         => $request->query('status'),
            'peer_node_code' => $request->query('peer_node_code'),
        ]);

        $result = $this->network->listTransfers($tenantId, $filters, $page, $perPage);
        return Response::paginated($result['items'], $result['total'], $result['page'], $result['per_page']);
    }

    public function showTransfer(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $transferId = $request->getAttribute('id');
        $transfer = $this->network->getTransfer($tenantId, $transferId);

        if (!$transfer) {
            return Response::error('Transfer not found', 404);
        }
        return Response::ok($transfer);
    }

    public function initiateTransfer(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $userId = $request->getAttribute('user_id');

        $validator = new Validator($request->all(), [
            'peer_node_code'        => 'required|string|max:20',
            'account_id'            => 'required|string',
            'sender_name'           => 'required|string|max:255',
            'sender_account'        => 'required|string|max:50',
            'sender_institution'    => 'required|string|max:255',
            'recipient_name'        => 'required|string|max:255',
            'recipient_account'     => 'required|string|max:50',
            'recipient_institution' => 'required|string|max:255',
            'amount'                => 'required|numeric|min:0.01',
            'fee'                   => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();

        try {
            $result = $this->network->initiateOutboundTransfer(
                $tenantId,
                $data['peer_node_code'],
                $data['account_id'],
                $data['sender_name'],
                $data['sender_account'],
                $data['sender_institution'],
                $data['recipient_name'],
                $data['recipient_account'],
                $data['recipient_institution'],
                (float)$data['amount'],
                (float)($data['fee'] ?? 0),
                'USD',
                $userId,
            );
            return Response::created($result, 'Transfer initiated.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // ------------------------------------------------------------------
    // Settlements
    // ------------------------------------------------------------------

    public function listSettlements(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $filters = array_filter([
            'peer_node_code' => $request->query('peer_node_code'),
            'status'         => $request->query('status'),
        ]);

        $settlements = $this->network->listSettlements($tenantId, $filters);
        return Response::ok($settlements);
    }

    public function showSettlement(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $settlementId = $request->getAttribute('id');
        $settlement = $this->network->getSettlement($tenantId, $settlementId);

        if (!$settlement) {
            return Response::error('Settlement not found', 404);
        }
        return Response::ok($settlement);
    }

    public function generateSettlement(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $validator = new Validator($request->all(), [
            'peer_node_code' => 'required|string|max:20',
            'period_start'   => 'required|date',
            'period_end'     => 'required|date',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();

        try {
            $result = $this->network->generateSettlement(
                $tenantId,
                $data['peer_node_code'],
                $data['period_start'],
                $data['period_end'],
            );
            return Response::created($result, 'Settlement generated.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function approveSettlement(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $userId = $request->getAttribute('user_id');
        $settlementId = $request->getAttribute('id');

        try {
            $result = $this->network->approveSettlement($tenantId, $settlementId, $userId);
            return Response::ok($result, 'Settlement approved.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function updateSettlementStatus(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $settlementId = $request->getAttribute('id');

        $validator = new Validator($request->all(), [
            'status' => 'required|string|in:draft,pending_approval,approved,invoiced,paid,disputed',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $result = $this->network->updateSettlementStatus(
                $tenantId,
                $settlementId,
                $validator->validated()['status'],
            );
            return Response::ok($result, 'Settlement status updated.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // ------------------------------------------------------------------
    // Node-to-Node API (called by peer CU instances)
    // ------------------------------------------------------------------

    public function nodeReceiveTransfer(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $validator = new Validator($request->all(), [
            'peer_node_code'        => 'required|string|max:20',
            'remote_reference'      => 'required|string|max:50',
            'sender_name'           => 'required|string|max:255',
            'sender_account'        => 'required|string|max:50',
            'sender_institution'    => 'required|string|max:255',
            'recipient_name'        => 'required|string|max:255',
            'recipient_account'     => 'required|string|max:50',
            'recipient_institution' => 'required|string|max:255',
            'amount'                => 'required|numeric|min:0.01',
            'currency'              => 'nullable|string|max:3',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();

        try {
            $result = $this->network->receiveInboundTransfer(
                $tenantId,
                $data['peer_node_code'],
                $data['remote_reference'],
                $data['sender_name'],
                $data['sender_account'],
                $data['sender_institution'],
                $data['recipient_name'],
                $data['recipient_account'],
                $data['recipient_institution'],
                (float)$data['amount'],
                $data['currency'] ?? 'USD',
            );
            return Response::created($result, 'Transfer received and credited.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function nodeHeartbeat(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $node = $request->getAttribute('authenticated_node');

        if ($node) {
            $this->network->recordHeartbeat($tenantId, $node['id']);
        }

        return Response::ok([
            'status' => 'alive',
            'time'   => date('c'),
        ]);
    }

    public function nodeConfirmTransfer(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $transferId = $request->getAttribute('id');

        $validator = new Validator($request->all(), [
            'remote_reference' => 'required|string|max:50',
            'status'           => 'required|string|in:confirmed,failed',
            'failure_reason'   => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();

        $transfer = $this->network->getTransfer($tenantId, $transferId);
        if (!$transfer) {
            return Response::error('Transfer not found', 404);
        }

        $this->network->updateTransferStatus(
            $tenantId,
            $transferId,
            $data['status'],
            $data['failure_reason'] ?? null,
        );

        if (!empty($data['remote_reference'])) {
            $this->network->getTransfer($tenantId, $transferId);
        }

        return Response::ok(null, 'Transfer status updated.');
    }
}
