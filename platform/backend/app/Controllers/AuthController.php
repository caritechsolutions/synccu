<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuthService;

/**
 * Authentication REST controller.
 */
final class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string|min:1',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $result = $this->auth->login(
                $request->input('email'),
                $request->input('password'),
                $request->getAttribute('tenant_id'),
            );

            return Response::ok($result, 'Login successful');
        } catch (\RuntimeException $e) {
            $code = $e->getCode() >= 400 ? $e->getCode() : 401;
            return Response::error($e->getMessage(), $code);
        }
    }

    /**
     * POST /api/auth/register
     */
    public function register(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'email'      => 'required|email|max:255',
            'password'   => 'required|string|min:8|max:128',
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $data['tenant_id'] = $request->getAttribute('tenant_id');
            $data['role']      = 'member'; // New registrations always get member role

            $result = $this->auth->register($data);

            return Response::created($result, 'Registration successful');
        } catch (\RuntimeException $e) {
            $code = $e->getCode() >= 400 ? $e->getCode() : 400;
            return Response::error($e->getMessage(), $code);
        }
    }

    /**
     * POST /api/auth/refresh
     */
    public function refresh(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $result = $this->auth->refresh($request->input('refresh_token'));
            return Response::ok($result, 'Token refreshed');
        } catch (\RuntimeException $e) {
            $code = $e->getCode() >= 400 ? $e->getCode() : 401;
            return Response::error($e->getMessage(), $code);
        }
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');

        if ($userId === null) {
            return Response::error('Not authenticated', 401);
        }

        $this->auth->logout($userId);

        return Response::ok(null, 'Logged out successfully');
    }

    /**
     * POST /api/auth/change-password
     */
    public function changePassword(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return Response::error('Not authenticated', 401);
        }

        $validator = new Validator($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|max:128',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $this->auth->changePassword(
                $userId,
                $request->input('current_password'),
                $request->input('new_password'),
            );
            return Response::ok(null, 'Password changed successfully. Please log in again.');
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return Response::error($e->getMessage(), $code >= 400 ? $code : 400);
        }
    }

    /**
     * POST /api/auth/forgot-password
     */
    public function forgotPassword(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $result = $this->auth->forgotPassword(
            $request->input('email'),
            $request->getAttribute('tenant_id'),
        );

        return Response::ok($result);
    }
}
