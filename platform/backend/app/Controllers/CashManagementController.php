<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\CashManagementService;

final class CashManagementController
{
    private CashManagementService $cash;

    public function __construct()
    {
        $this->cash = new CashManagementService();
    }

    // ------------------------------------------------------------------
    // Fund Locations
    // ------------------------------------------------------------------

    public function listLocations(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $filters = array_filter([
            'type'   => $request->query('type'),
            'status' => $request->query('status'),
        ]);

        $locations = $this->cash->listLocations($tenantId, $filters);
        return Response::ok($locations);
    }

    public function createLocation(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $validator = new Validator($request->all(), [
            'type'                => 'required|in:vault,drawer,bank_account',
            'name'                => 'required|string|max:255',
            'assigned_user_id'    => 'nullable|string',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $location = $this->cash->createLocation(
                $tenantId, $data['type'], $data['name'],
                $data['assigned_user_id'] ?? null,
                $data['bank_name'] ?? null,
                $data['bank_account_number'] ?? null,
            );
            return Response::created($location, 'Fund location created.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function showLocation(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $locationId = $request->param('id');
        $location = $this->cash->getLocation($tenantId, $locationId);

        if (!$location) {
            return Response::error('Location not found', 404);
        }
        return Response::ok($location);
    }

    public function locationDenominations(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $locationId = $request->param('id');
        $denoms = $this->cash->getLocationDenominations($tenantId, $locationId);
        return Response::ok($denoms);
    }

    public function updateLocation(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $locationId = $request->param('id');

        $validator = new Validator($request->all(), [
            'name'                => 'nullable|string|max:255',
            'status'              => 'nullable|in:active,closed,suspended',
            'assigned_user_id'    => 'nullable|string',
            'bank_name'           => 'nullable|string',
            'bank_account_number' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $location = $this->cash->updateLocation($tenantId, $locationId, $validator->validated());
            return Response::ok($location, 'Location updated.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // ------------------------------------------------------------------
    // Summary
    // ------------------------------------------------------------------

    public function summary(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $summary = $this->cash->getLocationSummary($tenantId);
        return Response::ok($summary);
    }

    // ------------------------------------------------------------------
    // Cash Operations
    // ------------------------------------------------------------------

    public function dispense(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $userId = $request->getAttribute('user_id');

        $validator = new Validator($request->all(), [
            'vault_id'      => 'required|string',
            'drawer_id'     => 'required|string',
            'amount'        => 'required|numeric|min:0.01',
            'denominations' => 'required',
            'approved_by'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $result = $this->cash->dispenseToDrawer(
                $tenantId, $data['vault_id'], $data['drawer_id'],
                (float)$data['amount'], $data['denominations'] ?? [],
                $userId, $data['approved_by'] ?? null,
            );
            return Response::created($result, 'Cash dispensed to drawer.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function returnCash(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $userId = $request->getAttribute('user_id');

        $validator = new Validator($request->all(), [
            'drawer_id'     => 'required|string',
            'vault_id'      => 'required|string',
            'amount'        => 'required|numeric|min:0.01',
            'denominations' => 'required',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $result = $this->cash->returnToVault(
                $tenantId, $data['drawer_id'], $data['vault_id'],
                (float)$data['amount'], $data['denominations'] ?? [],
                $userId,
            );
            return Response::created($result, 'Cash returned to vault.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function bankTransfer(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $userId = $request->getAttribute('user_id');

        $validator = new Validator($request->all(), [
            'location_id' => 'required|string',
            'direction'   => 'required|in:in,out',
            'amount'      => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'vault_id'    => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $result = $this->cash->recordBankTransfer(
                $tenantId, $data['location_id'], $data['direction'],
                (float)$data['amount'], $userId,
                $data['description'] ?? '',
                $data['vault_id'] ?? null,
            );
            return Response::created($result, 'Bank transfer recorded.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function adjust(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $userId = $request->getAttribute('user_id');

        $validator = new Validator($request->all(), [
            'location_id'  => 'required|string',
            'amount'       => 'required|numeric',
            'denominations' => 'nullable',
            'description'  => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $result = $this->cash->adjustBalance(
                $tenantId, $data['location_id'], (float)$data['amount'],
                $data['denominations'] ?? [], $userId,
                $data['description'] ?? '',
            );
            return Response::created($result, 'Balance adjusted.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // ------------------------------------------------------------------
    // Movements
    // ------------------------------------------------------------------

    public function listMovements(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $filters = array_filter([
            'type'        => $request->query('type'),
            'location_id' => $request->query('location_id'),
            'date_from'   => $request->query('date_from'),
            'date_to'     => $request->query('date_to'),
        ]);
        $page = (int)($request->query('page') ?: 1);
        $perPage = (int)($request->query('per_page') ?: 25);

        $movements = $this->cash->listMovements($tenantId, $filters, $page, $perPage);
        return Response::ok($movements);
    }

    public function showMovement(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $movementId = $request->param('id');
        $movement = $this->cash->getMovement($tenantId, $movementId);

        if (!$movement) {
            return Response::error('Movement not found', 404);
        }
        return Response::ok($movement);
    }

    // ------------------------------------------------------------------
    // Drawer Sessions
    // ------------------------------------------------------------------

    public function listSessions(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $filters = array_filter([
            'status'         => $request->query('status'),
            'teller_user_id' => $request->query('teller_user_id'),
            'date_from'      => $request->query('date_from'),
            'date_to'        => $request->query('date_to'),
        ]);
        $page = (int)($request->query('page') ?: 1);
        $perPage = (int)($request->query('per_page') ?: 25);

        $sessions = $this->cash->listSessions($tenantId, $filters, $page, $perPage);
        return Response::ok($sessions);
    }

    public function openSession(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $userId = $request->getAttribute('user_id');

        $validator = new Validator($request->all(), [
            'drawer_id'       => 'required|string',
            'opening_balance' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $session = $this->cash->openDrawerSession(
                $tenantId, $data['drawer_id'], $userId,
                (float)$data['opening_balance'],
            );
            return Response::created($session, 'Drawer session opened.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function closeSession(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $userId = $request->getAttribute('user_id');
        $sessionId = $request->param('id');

        $validator = new Validator($request->all(), [
            'actual_balance' => 'required|numeric|min:0',
            'denominations'  => 'required',
            'notes'          => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $session = $this->cash->closeDrawerSession(
                $tenantId, $sessionId, (float)$data['actual_balance'],
                $data['denominations'] ?? [], $userId,
                $data['notes'] ?? null,
            );
            return Response::ok($session, 'Drawer session closed.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function getMyDrawer(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $userId = $request->getAttribute('user_id');
        $drawer = $this->cash->getMyDrawer($tenantId, $userId);

        if (!$drawer) {
            return Response::error('No drawer assigned', 404);
        }
        return Response::ok($drawer);
    }
}
