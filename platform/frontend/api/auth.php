<?php
/**
 * SyncCU - Standalone Auth API
 * Handles login/logout without the full framework bootstrap.
 * Place in the frontend-accessible path so nginx can serve it.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

// Determine action from URL query string or path
$action = $_GET['action'] ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Database connection - search for .env in known locations
$envFile = null;
$scriptDir = __DIR__;  // e.g. /var/www/synccu/platform/frontend/api
$searchPaths = [
    dirname($scriptDir, 2) . '/backend/.env',   // up 2 from api/ -> /platform/backend/.env
    dirname($scriptDir) . '/../backend/.env',    // up 1 from api/ then ../backend
    '/var/www/synccu/platform/backend/.env',     // absolute fallback
    '/opt/synccu/platform/backend/.env',         // alt install location
];
foreach ($searchPaths as $path) {
    $resolved = realpath($path);
    if ($resolved && is_readable($resolved)) {
        $envFile = $resolved;
        break;
    }
}

$dbHost = '127.0.0.1';
$dbName = 'synccu';
$dbUser = 'synccu_user';
$dbPass = '';
$jwtSecret = '';

if ($envFile) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;
        $key = trim(substr($line, 0, $eqPos));
        $val = trim(substr($line, $eqPos + 1));
        // Remove surrounding quotes if present
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        switch ($key) {
            case 'DB_HOST': $dbHost = $val; break;
            case 'DB_DATABASE': $dbName = $val; break;
            case 'DB_USERNAME': $dbUser = $val; break;
            case 'DB_PASSWORD': $dbPass = $val; break;
            case 'JWT_SECRET': $jwtSecret = $val; break;
        }
    }
} else {
    // .env not found - return helpful debug info (remove in production)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Configuration error: cannot find .env file',
        'debug' => [
            'script_dir' => $scriptDir,
            'searched' => $searchPaths,
        ]
    ]);
    exit;
}

if (!$jwtSecret) {
    $jwtSecret = hash('sha256', $dbPass . $dbName . 'synccu-fallback');
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
        'debug' => [
            'host' => $dbHost,
            'db' => $dbName,
            'user' => $dbUser,
            'env_file' => $envFile ?: 'NOT FOUND',
            'error' => $e->getMessage(),
        ]
    ]);
    exit;
}

// ─── JWT Helpers ────────────────────────────────────────

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function create_jwt(array $payload, string $secret, int $ttl = 3600): string {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + $ttl;
    $body = base64url_encode(json_encode($payload));
    $signature = base64url_encode(hash_hmac('sha256', "{$header}.{$body}", $secret, true));
    return "{$header}.{$body}.{$signature}";
}

// ─── Rate Limiting (simple file-based) ──────────────────

function check_rate_limit(string $ip, int $maxAttempts = 10, int $windowSeconds = 300): bool {
    $dir = sys_get_temp_dir() . '/synccu_rate';
    @mkdir($dir, 0755, true);
    $file = $dir . '/' . md5($ip);

    $attempts = [];
    if (file_exists($file)) {
        $attempts = json_decode(file_get_contents($file), true) ?: [];
        $attempts = array_filter($attempts, fn($t) => $t > time() - $windowSeconds);
    }

    if (count($attempts) >= $maxAttempts) {
        return false;
    }

    $attempts[] = time();
    file_put_contents($file, json_encode($attempts), LOCK_EX);
    return true;
}

// ─── Actions ────────────────────────────────────────────

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

switch ($action) {
    case 'login':
        // Rate limit
        if (!check_rate_limit($clientIp, 10, 300)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many login attempts. Try again in 5 minutes.']);
            exit;
        }

        $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email and password are required']);
            exit;
        }

        // Find user
        $stmt = $pdo->prepare("
            SELECT id, tenant_id, email, password_hash, first_name, last_name, role, status,
                   failed_login_attempts, locked_until
            FROM users
            WHERE email = ? AND status != 'inactive'
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
            exit;
        }

        // Check if locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            http_response_code(423);
            echo json_encode(['success' => false, 'message' => "Account locked. Try again in {$remaining} minutes."]);
            exit;
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            // Increment failed attempts
            $attempts = ($user['failed_login_attempts'] ?? 0) + 1;
            $lockUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;

            $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?");
            $stmt->execute([$attempts, $lockUntil, $user['id']]);

            // Audit log
            $stmt = $pdo->prepare("INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) VALUES (UUID(), ?, ?, 'login_failed', 'user', ?, ?, ?, NOW())");
            $stmt->execute([$user['tenant_id'], $user['id'], $user['id'], $clientIp, $_SERVER['HTTP_USER_AGENT'] ?? '']);

            http_response_code(401);
            $msg = $attempts >= 5 ? 'Account locked for 15 minutes due to too many failed attempts.' : 'Invalid email or password';
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        // Check account status
        if ($user['status'] === 'locked') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Account is locked. Contact your administrator.']);
            exit;
        }

        // Success - clear failed attempts, update last login
        $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
        $stmt->execute([$clientIp, $user['id']]);

        // Create tokens
        $accessToken = create_jwt([
            'sub' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ], $jwtSecret, 3600);

        $refreshToken = create_jwt([
            'sub' => $user['id'],
            'type' => 'refresh',
        ], $jwtSecret, 604800);

        // Store refresh token hash
        $tokenHash = hash('sha256', $refreshToken);
        $stmt = $pdo->prepare("INSERT INTO refresh_tokens (id, user_id, token_hash, expires_at, created_at) VALUES (UUID(), ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())");
        $stmt->execute([$user['id'], $tokenHash]);

        // Audit log
        $stmt = $pdo->prepare("INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) VALUES (UUID(), ?, ?, 'login_success', 'user', ?, ?, ?, NOW())");
        $stmt->execute([$user['tenant_id'], $user['id'], $user['id'], $clientIp, $_SERVER['HTTP_USER_AGENT'] ?? '']);

        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'role' => $user['role'],
                'tenant_id' => $user['tenant_id'],
            ],
        ]);
        break;

    case 'me':
        // Verify JWT from Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'No token provided']);
            exit;
        }

        $token = $matches[1];
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid token']);
            exit;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload || ($payload['exp'] ?? 0) < time()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token expired']);
            exit;
        }

        // Verify signature
        $validSig = base64url_encode(hash_hmac('sha256', "{$parts[0]}.{$parts[1]}", $jwtSecret, true));
        if (!hash_equals($validSig, $parts[2])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid token']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, tenant_id, email, first_name, last_name, role, status FROM users WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }

        echo json_encode(['success' => true, 'user' => $user]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
