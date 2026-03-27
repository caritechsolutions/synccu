<?php

declare(strict_types=1);

/**
 * API Route Definitions
 *
 * All routes are prefixed with /api and grouped by resource with
 * appropriate middleware applied at each level.
 *
 * Middleware abbreviations used:
 *   auth   = App\Middleware\AuthMiddleware
 *   tenant = App\Middleware\TenantMiddleware
 *   rbac   = App\Middleware\RBACMiddleware
 *   rate   = App\Middleware\RateLimitMiddleware
 *   audit  = App\Middleware\AuditMiddleware
 */

use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\LoanController;
use App\Controllers\TransactionController;
use App\Controllers\TenantController;
use App\Middleware\AuthMiddleware;
use App\Middleware\TenantMiddleware;
use App\Middleware\RBACMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\AuditMiddleware;

/** @var \App\Core\Router $router */

// ------------------------------------------------------------------
// Health check (no middleware)
// ------------------------------------------------------------------
$router->get('/api/health', function () {
    return \App\Core\Response::ok([
        'status'  => 'healthy',
        'version' => '1.0.0',
        'time'    => date('c'),
    ]);
});

// ------------------------------------------------------------------
// Auth routes (rate-limited, tenant-aware, no JWT required)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/auth',
    'middleware'  => [
        RateLimitMiddleware::class . ':10',
        TenantMiddleware::class,
    ],
], function ($router) {
    $router->post('/login',           [AuthController::class, 'login']);
    $router->post('/register',        [AuthController::class, 'register']);
    $router->post('/refresh',         [AuthController::class, 'refresh']);
    $router->post('/forgot-password', [AuthController::class, 'forgotPassword']);
});

// Auth logout requires authentication
$router->group([
    'prefix'     => '/api/auth',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
    ],
], function ($router) {
    $router->post('/logout', [AuthController::class, 'logout']);
});

// ------------------------------------------------------------------
// Account routes (authenticated, tenant-scoped)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/accounts',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/',                [AccountController::class, 'index']);
    $router->post('/',               [AccountController::class, 'store']);
    $router->get('/{id}',            [AccountController::class, 'show']);
    $router->put('/{id}',            [AccountController::class, 'update']);
    $router->get('/{id}/transactions', [AccountController::class, 'transactions']);
});

// ------------------------------------------------------------------
// Transaction routes (authenticated, tenant-scoped, audited)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/transactions',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->post('/deposit',   [TransactionController::class, 'deposit']);
    $router->post('/withdraw',  [TransactionController::class, 'withdraw']);
    $router->post('/transfer',  [TransactionController::class, 'transfer']);
    $router->get('/{id}',       [TransactionController::class, 'show']);
});

// ------------------------------------------------------------------
// Loan routes (authenticated, tenant-scoped, audited)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/loans',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/',               [LoanController::class, 'index']);
    $router->post('/apply',         [LoanController::class, 'apply']);
    $router->get('/{id}',           [LoanController::class, 'show']);
    $router->get('/{id}/schedule',  [LoanController::class, 'schedule']);
    $router->post('/{id}/payment',  [LoanController::class, 'payment']);

    // Approval/denial requires admin or manager role
    $router->put('/{id}/approve', [LoanController::class, 'approve'], [
        RBACMiddleware::class . ':admin,manager',
    ]);
    $router->put('/{id}/deny', [LoanController::class, 'deny'], [
        RBACMiddleware::class . ':admin,manager',
    ]);
});

// ------------------------------------------------------------------
// Admin routes (admin/manager only, tenant-scoped, audited)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/admin',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        RBACMiddleware::class . ':admin,manager',
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/dashboard',   [AdminController::class, 'dashboard']);
    $router->get('/users',       [AdminController::class, 'users']);
    $router->put('/users/{id}',  [AdminController::class, 'updateUser']);
    $router->get('/audit-logs',  [AdminController::class, 'auditLogs']);
    $router->get('/reports',     [AdminController::class, 'reports']);
});

// ------------------------------------------------------------------
// Tenant settings routes (admin only, tenant-scoped)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/tenant',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        RBACMiddleware::class . ':admin',
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/settings',    [TenantController::class, 'getSettings']);
    $router->put('/settings',    [TenantController::class, 'updateSettings']);
    $router->put('/branding',    [TenantController::class, 'updateBranding']);
});
