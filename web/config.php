<?php
// ──────────────────────────────────────────────
// Load environment variables from .env file
// ──────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'synccu');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// ──────────────────────────────────────────────
// Session hardening (must happen before session_start)
// ──────────────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}

session_start();

// Idle-timeout: 30 minutes
define('SESSION_TIMEOUT', 1800);
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// ──────────────────────────────────────────────
// Database connection
// ──────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    // Don't expose DB error details in production
    error_log("DB connection failed: " . $e->getMessage());
    die("Database connection failed. Please contact your administrator.");
}

// ──────────────────────────────────────────────
// CSRF helpers
// ──────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die("Invalid request token. Please go back and try again.");
    }
}

// ──────────────────────────────────────────────
// Password-strength validator
// ──────────────────────────────────────────────
function validatePasswordStrength(string $password): ?string {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number';
    }
    return null; // valid
}

// ──────────────────────────────────────────────
// Auth helpers
// ──────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function hasPermission(string $permission): bool {
    if (!isLoggedIn()) return false;

    global $pdo;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.id
        WHERE rp.role = ? AND p.permission_name = ?
    ");
    $stmt->execute([$_SESSION['user_role'], $permission]);
    return $stmt->fetchColumn() > 0;
}

function mustChangePassword(): bool {
    if (!isLoggedIn()) return false;

    global $pdo;
    $stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch();
    return $result && $result['must_change_password'];
}

function checkForcedPasswordChange(): void {
    if (mustChangePassword() && basename($_SERVER['PHP_SELF']) !== 'forced_password_change.php') {
        header('Location: forced_password_change.php');
        exit();
    }
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
    checkForcedPasswordChange();
}
