<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\BillPayService;

/**
 * Member portal bill pay controller.
 *
 * The source account and payee are always re-checked against the
 * authenticated member in the service layer; client-supplied member ids
 * are never trusted.
 */
final class BillPayController
{
    private BillPayService $billpay;

    public function __construct()
    {
        $this->billpay = new BillPayService();
    }

    // ------------------------------------------------------------------
    // Payees
    // ------------------------------------------------------------------

    /** GET /api/v1/payees */
    public function payees(Request $request): Response
    {
        $memberId = $request->getAttribute('user_id');
        return Response::ok(['payees' => $this->billpay->listPayees($memberId)]);
    }

    /** POST /api/v1/payees */
    public function addPayee(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'name'              => 'required|string|max:120',
            'category'          => 'nullable|string|max:50',
            'account_reference' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();
        $memberId = $request->getAttribute('user_id');
        $id = $this->billpay->addPayee(
            $memberId,
            $data['name'],
            $data['category'] ?? null,
            $data['account_reference'] ?? null,
        );

        return Response::created(['id' => $id]);
    }

    /** DELETE /api/v1/payees/{id} */
    public function deletePayee(Request $request): Response
    {
        $memberId = $request->getAttribute('user_id');
        $ok = $this->billpay->deletePayee($memberId, (string) $request->param('id'));
        if (!$ok) {
            return Response::error('Payee not found.', 404);
        }
        return Response::ok(['deleted' => true]);
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    /** GET /api/v1/billpay */
    public function history(Request $request): Response
    {
        $memberId = $request->getAttribute('user_id');
        return Response::ok(['payments' => $this->billpay->history($memberId)]);
    }

    /** POST /api/v1/billpay */
    public function pay(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'from_account_id' => 'required|string',
            'payee_id'        => 'required|string',
            'amount'          => 'required|numeric|positive',
            'memo'            => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $memberId = $request->getAttribute('user_id');
            $result = $this->billpay->pay(
                $memberId,
                $data['from_account_id'],
                $data['payee_id'],
                (float) $data['amount'],
                $data['memo'] ?? null,
            );

            return Response::created($result, 'Payment submitted');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }
}
