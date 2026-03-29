<?php

declare(strict_types=1);

/**
 * SyncCU Core Banking Platform - Entry Point
 *
 * Bootstraps the application, configures CORS, CSRF protection,
 * and dispatches incoming requests through the router.
 */

define('BASE_PATH', dirname(__DIR__));

// ---------- Autoloader ----------
spl_autoload_register(function (string $class): void {
    $prefixMap = [
        'App\\' => BASE_PATH . '/app/',
    ];

    foreach ($prefixMap as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ---------- Environment Loader ----------
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip surrounding quotes
        if (preg_match('/^"(.*)"$/', $value, $m)) {
            $value = $m[1];
        } elseif (preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }

        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

/**
 * Retrieve an environment variable with an optional default.
 */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return match (strtolower((string) $value)) {
        'true', '(true)'   => true,
        'false', '(false)' => false,
        'null', '(null)'   => null,
        default             => $value,
    };
}

// Load .env file
loadEnv(BASE_PATH . '/.env');

// ---------- Error Handling ----------
$debug = env('APP_DEBUG', false);

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (\Throwable $e) use ($debug): void {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');

    $payload = ['error' => $e->getMessage()];
    if ($debug) {
        $payload['file'] = $e->getFile() . ':' . $e->getLine();
        $payload['trace'] = $e->getTraceAsString();
    }

    echo json_encode($payload);
    exit;
});

// ---------- CORS ----------
$allowedOrigins = array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', env('APP_URL', '*'))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array('*', $allowedOrigins, true) || in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
} elseif (count($allowedOrigins) === 1) {
    header("Access-Control-Allow-Origin: {$allowedOrigins[0]}");
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Tenant-ID');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------- CSRF Protection (non-API browser requests) ----------
session_start();

function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool
{
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ---------- Bootstrap Core ----------
use App\Core\Router;
use App\Core\Request;
use App\Core\Response;

$router  = new Router();
$request = new Request();

// ---------- Load Routes ----------
require BASE_PATH . '/routes/api.php';

// ---------- Dispatch ----------
try {
    $response = $router->dispatch($request);

    if ($response instanceof Response) {
        $response->send();
    }
} catch (\Throwable $e) {
    $code = is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    $payload = ['error' => $e->getMessage()];
    if ($debug) {
        $payload['file'] = $e->getFile() . ':' . $e->getLine();
        $payload['trace'] = $e->getTraceAsString();
    }
    Response::json($payload, $code)->send();
}
