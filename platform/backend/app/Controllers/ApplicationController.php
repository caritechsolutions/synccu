<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ApplicationService;

/**
 * Member portal application controller.
 *
 * Member endpoints always derive the member id from the authenticated token;
 * a client-supplied member id is never trusted.
 */
final class ApplicationController
{
    private ApplicationService $applications;

    public function __construct()
    {
        $this->applications = new ApplicationService();
    }

    // ------------------------------------------------------------------
    // Member endpoints
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/applications
     *
     * List the authenticated member's applications.
     */
    public function index(Request $request): Response
    {
        $memberId = $request->getAttribute('user_id');
        return Response::ok(['applications' => $this->applications->listForMember($memberId)]);
    }

    /**
     * POST /api/v1/applications
     *
     * Submit a new application for the authenticated member.
     */
    public function store(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'app_type'    => 'required|in:loan,service',
            'product'     => 'required|string|max:120',
            'amount'      => 'nullable|numeric',
            'term_months' => 'nullable|integer',
            'details'     => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();

        $amount = isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : null;
        $term   = isset($data['term_months']) && $data['term_months'] !== '' ? (int) $data['term_months'] : null;

        // Loan applications require a positive amount
        if ($data['app_type'] === 'loan' && ($amount === null || $amount <= 0)) {
            return Response::validationError([
                'amount' => ['The amount field must be a positive number for loan applications.'],
            ]);
        }

        $memberId = $request->getAttribute('user_id');
        $id = $this->applications->submit(
            $memberId,
            $data['app_type'],
            $data['product'],
            $amount,
            $term,
            $data['details'] ?? null,
        );

        return Response::created(['id' => $id]);
    }

    /**
     * POST /api/v1/applications/{id}/cancel
     *
     * Cancel the authenticated member's own application.
     */
    public function cancel(Request $request): Response
    {
        $memberId = $request->getAttribute('user_id');
        $id = $request->param('id');

        $cancelled = $this->applications->cancelOwn($memberId, $id);
        if (!$cancelled) {
            return Response::error('Application cannot be cancelled.', 422);
        }

        return Response::ok(['id' => $id, 'status' => 'cancelled']);
    }

    // ------------------------------------------------------------------
    // Staff endpoints
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/admin/applications
     *
     * List all applications for staff, optionally filtered by ?status=.
     */
    public function adminIndex(Request $request): Response
    {
        $status = $request->query('status');
        return Response::ok(['applications' => $this->applications->listAll($status)]);
    }

    /**
     * PUT /api/v1/admin/applications/{id}
     *
     * Review an application (update status and staff notes).
     */
    public function adminUpdate(Request $request): Response
    {
        $id = $request->param('id');

        $validator = new Validator($request->all(), [
            'status'      => 'required|in:pending,in_review,approved,declined,cancelled',
            'staff_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();
        $reviewerId = $request->getAttribute('user_id');

        $updated = $this->applications->review(
            $id,
            $data['status'],
            $data['staff_notes'] ?? null,
            $reviewerId,
        );

        if (!$updated) {
            return Response::error('Application not found.', 404);
        }

        return Response::ok(['id' => $id, 'status' => $data['status']]);
    }
}
