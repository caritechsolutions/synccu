<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\MessageService;

/**
 * Member portal messaging controller.
 *
 * Member endpoints always derive the member id from the authenticated token;
 * a client-supplied member id is never trusted.
 */
final class MessageController
{
    private MessageService $messages;

    public function __construct()
    {
        $this->messages = new MessageService();
    }

    // ------------------------------------------------------------------
    // Member endpoints
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/messages
     *
     * Return the authenticated member's message thread.
     */
    public function index(Request $request): Response
    {
        $memberId = $request->getAttribute('user_id');
        $this->messages->markReadByMember($memberId);
        return Response::ok(['messages' => $this->messages->threadForMember($memberId)]);
    }

    /**
     * POST /api/v1/messages
     *
     * Send a message from the authenticated member to staff.
     */
    public function store(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'subject' => 'required|string|max:150',
            'body'    => 'required|string|max:4000',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();
        $memberId = $request->getAttribute('user_id');
        $id = $this->messages->sendFromMember($memberId, $data['subject'], $data['body']);

        return Response::created(['id' => $id]);
    }

    // ------------------------------------------------------------------
    // Staff endpoints
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/admin/messages
     *
     * List member conversations for staff.
     */
    public function conversations(Request $request): Response
    {
        return Response::ok(['conversations' => $this->messages->conversations()]);
    }

    /**
     * GET /api/v1/admin/messages/{memberId}
     *
     * Return a member's thread (staff view) and mark member messages read.
     */
    public function thread(Request $request): Response
    {
        $memberId = $request->param('memberId');
        $this->messages->markReadByStaff($memberId);
        return Response::ok(['messages' => $this->messages->threadForStaff($memberId)]);
    }

    /**
     * POST /api/v1/admin/messages/{memberId}
     *
     * Send a staff reply to a member.
     */
    public function reply(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'subject' => 'required|string|max:150',
            'body'    => 'required|string|max:4000',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();
        $memberId = $request->param('memberId');
        $staffId = $request->getAttribute('user_id');
        $id = $this->messages->sendFromStaff($memberId, $staffId, $data['subject'], $data['body']);

        return Response::created(['id' => $id]);
    }
}
