<?php
// includes/auth.php

if (session_status() === PHP_SESSION_NONE) {
    // Session security configurations
    @ini_set('session.cookie_httponly', 1);
    @ini_set('session.use_only_cookies', 1);
    
    // Auto-detect HTTPS and enable secure cookies if applicable
    $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    if ($is_secure) {
        @ini_set('session.cookie_secure', 1);
    }
    
    session_start();
}

/**
 * Check if the current user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Require user to be logged in, otherwise redirect to login page
 */
function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

/**
 * Check if any admin account exists in the database.
 * If not, we allow migrations to be run or setup to be executed.
 */
function any_admin_exists() {
    global $conn;
    if (!$conn) return false;
    
    // Check if DB exists and select it
    if (!@$conn->select_db(DB_NAME)) return false;
    
    // Check if admins table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'admins'");
    if (!$table_check || $table_check->num_rows === 0) {
        return false;
    }
    
    $result = $conn->query("SELECT COUNT(*) as count FROM `admins`");
    if ($result) {
        $row = $result->fetch_assoc();
        $result->free();
        return $row['count'] > 0;
    }
    return false;
}

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
