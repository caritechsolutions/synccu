<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'synccu');
define('DB_USER', 'root');
define('DB_PASS', 'your_mysql_password'); // Replace with your actual MySQL root password

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Start session
session_start();

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to check if user has permission
function hasPermission($permission) {
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

// Function to redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}
?>
