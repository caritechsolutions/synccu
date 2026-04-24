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
use App\Controllers\CashManagementController;
use App\Controllers\LoanController;
use App\Controllers\TransactionController;
use App\Controllers\TenantController;
use App\Controllers\DocumentController;
use App\Controllers\ReportController;
use App\Controllers\NetworkController;
use App\Middleware\AuthMiddleware;
use App\Middleware\TenantMiddleware;
use App\Middleware\RBACMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\AuditMiddleware;
use App\Middleware\NodeAuthMiddleware;

/** @var \App\Core\Router $router */

// ------------------------------------------------------------------
// Health check (no middleware)
// ------------------------------------------------------------------
$router->get('/api/v1/health', function () {
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
    'prefix'     => '/api/v1/auth',
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
    'prefix'     => '/api/v1/auth',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
    ],
], function ($router) {
    $router->post('/logout',          [AuthController::class, 'logout']);
    $router->post('/change-password', [AuthController::class, 'changePassword']);
});

// ------------------------------------------------------------------
// Account routes (authenticated, tenant-scoped)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/accounts',
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
    'prefix'     => '/api/v1/transactions',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/',           [TransactionController::class, 'index']);
    $router->get('/export',     [TransactionController::class, 'export']);
    $router->post('/',          [TransactionController::class, 'store']);
    $router->post('/deposit',   [TransactionController::class, 'deposit']);
    $router->post('/withdraw',  [TransactionController::class, 'withdraw']);
    $router->post('/transfer',  [TransactionController::class, 'transfer']);
    $router->post('/expense',            [TransactionController::class, 'recordExpense'], [
        RBACMiddleware::class . ':admin,manager',
    ]);
    $router->post('/external-transfer',  [TransactionController::class, 'externalTransfer']);
    $router->put('/{id}/status',         [TransactionController::class, 'updateStatus']);
    $router->post('/{id}/receipt',       [TransactionController::class, 'sendReceipt']);
    $router->get('/{id}',                [TransactionController::class, 'show']);
});

// ------------------------------------------------------------------
// Document routes (authenticated, tenant-scoped)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/documents',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
    ],
], function ($router) {
    $router->get('/',                [DocumentController::class, 'index']);
    $router->post('/',               [DocumentController::class, 'upload']);
    $router->get('/{id}/download',   [DocumentController::class, 'download']);
    $router->delete('/{id}',         [DocumentController::class, 'destroy']);
});

// ------------------------------------------------------------------
// Loan routes (authenticated, tenant-scoped, audited)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/loans',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/',               [LoanController::class, 'index']);
    $router->get('/export',         [LoanController::class, 'export']);
    $router->post('/apply',         [LoanController::class, 'apply']);
    $router->get('/{id}',           [LoanController::class, 'show']);
    $router->put('/{id}',           [LoanController::class, 'update']);
    $router->get('/{id}/payments',  [LoanController::class, 'paymentHistory']);
    $router->get('/{id}/payoff',    [LoanController::class, 'payoffQuote']);
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
// Members routes (admin/manager/teller, tenant-scoped)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/members',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        RBACMiddleware::class . ':admin,manager,teller',
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/',            [AdminController::class, 'members']);
    $router->post('/',           [AdminController::class, 'createMember']);
    $router->get('/{id}',        [AdminController::class, 'showMember']);
    $router->put('/{id}',       [AdminController::class, 'updateUser']);
    $router->delete('/{id}',    [AdminController::class, 'deleteMember']);
});

// ------------------------------------------------------------------
// Admin routes (admin/manager only, tenant-scoped, audited)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/admin',
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
    $router->get('/loan-stats',  [AdminController::class, 'loanStats']);
    $router->post('/reconcile',          [AdminController::class, 'reconcile']);
    $router->post('/test-db-connection', [AdminController::class, 'testDbConnection']);
    $router->get('/backup',              [AdminController::class, 'backup']);
    $router->post('/restore',            [AdminController::class, 'restore']);
});

// ------------------------------------------------------------------
// Report routes (admin/manager only, tenant-scoped)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/reports',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        RBACMiddleware::class . ':admin,manager',
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/general-ledger',     [ReportController::class, 'generalLedger']);
    $router->get('/income-statement',   [ReportController::class, 'incomeStatement']);
    $router->get('/balance-sheet',      [ReportController::class, 'balanceSheet']);
    $router->get('/member-growth',      [ReportController::class, 'memberGrowth']);
    $router->get('/delinquency',        [ReportController::class, 'delinquency']);
    $router->get('/export',             [ReportController::class, 'export']);
});

// ------------------------------------------------------------------
// Tenant settings routes (admin only, tenant-scoped)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/tenant',
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

// ------------------------------------------------------------------
// Cash Management routes (admin/manager, tenant-scoped, audited)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/cash',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        RBACMiddleware::class . ':admin,manager',
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/summary',             [CashManagementController::class, 'summary']);
    $router->get('/locations',           [CashManagementController::class, 'listLocations']);
    $router->post('/locations',          [CashManagementController::class, 'createLocation']);
    $router->get('/locations/{id}',      [CashManagementController::class, 'showLocation']);
    $router->get('/locations/{id}/denominations', [CashManagementController::class, 'locationDenominations']);
    $router->put('/locations/{id}',      [CashManagementController::class, 'updateLocation']);
    $router->post('/dispense',           [CashManagementController::class, 'dispense']);
    $router->post('/return',             [CashManagementController::class, 'returnCash']);
    $router->post('/bank-transfer',      [CashManagementController::class, 'bankTransfer']);
    $router->post('/adjust',             [CashManagementController::class, 'adjust']);
    $router->get('/movements',           [CashManagementController::class, 'listMovements']);
    $router->get('/movements/{id}',      [CashManagementController::class, 'showMovement']);
    $router->get('/sessions',            [CashManagementController::class, 'listSessions']);
    $router->post('/sessions/open',      [CashManagementController::class, 'openSession']);
    $router->post('/sessions/{id}/close', [CashManagementController::class, 'closeSession']);
});

// Teller-accessible cash routes
$router->group([
    'prefix'     => '/api/v1/cash',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        RBACMiddleware::class . ':admin,manager,teller',
        AuditMiddleware::class,
    ],
], function ($router) {
    $router->get('/my-drawer',           [CashManagementController::class, 'getMyDrawer']);
});

// ------------------------------------------------------------------
// Network routes (admin/manager, tenant-scoped, audited)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/network',
    'middleware'  => [
        RateLimitMiddleware::class,
        AuthMiddleware::class,
        TenantMiddleware::class,
        RBACMiddleware::class . ':admin,manager',
        AuditMiddleware::class,
    ],
], function ($router) {
    // Stats
    $router->get('/stats',                  [NetworkController::class, 'stats']);

    // Nodes
    $router->get('/nodes',                  [NetworkController::class, 'listNodes']);
    $router->post('/nodes',                 [NetworkController::class, 'registerNode']);
    $router->get('/nodes/{id}',             [NetworkController::class, 'showNode']);
    $router->put('/nodes/{id}',             [NetworkController::class, 'updateNode']);
    $router->delete('/nodes/{id}',          [NetworkController::class, 'deleteNode']);
    $router->put('/nodes/{id}/activate',    [NetworkController::class, 'activateNode']);
    $router->post('/nodes/{id}/regenerate-key', [NetworkController::class, 'regenerateApiKey']);

    // Transfers
    $router->get('/transfers',              [NetworkController::class, 'listTransfers']);
    $router->post('/transfers',             [NetworkController::class, 'initiateTransfer']);
    $router->get('/transfers/{id}',         [NetworkController::class, 'showTransfer']);

    // Settlements
    $router->get('/settlements',            [NetworkController::class, 'listSettlements']);
    $router->post('/settlements',           [NetworkController::class, 'generateSettlement']);
    $router->get('/settlements/{id}',       [NetworkController::class, 'showSettlement']);
    $router->put('/settlements/{id}/approve',   [NetworkController::class, 'approveSettlement']);
    $router->put('/settlements/{id}/status',    [NetworkController::class, 'updateSettlementStatus']);
});

// ------------------------------------------------------------------
// Node-to-Node API (called by peer CU instances, API key auth)
// ------------------------------------------------------------------
$router->group([
    'prefix'     => '/api/v1/node',
    'middleware'  => [
        RateLimitMiddleware::class . ':30',
        TenantMiddleware::class,
        NodeAuthMiddleware::class,
    ],
], function ($router) {
    $router->post('/transfer',              [NetworkController::class, 'nodeReceiveTransfer']);
    $router->post('/transfer/{id}/confirm', [NetworkController::class, 'nodeConfirmTransfer']);
    $router->post('/heartbeat',             [NetworkController::class, 'nodeHeartbeat']);
});
