<?php
// includes/db_config.php

// Database configuration settings
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'playerkit_db');
define('DB_PORT', 3306);

$conn = null;
$db_connection_error = null;

try {
    // Try to connect to MySQL server (without selecting DB first in case it doesn't exist yet)
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    
    // Attempt to select the database
    $db_selected = @$conn->select_db(DB_NAME);
    if (!$db_selected) {
        $db_connection_error = "Database '" . DB_NAME . "' does not exist. Use the migration tracker to create it.";
    }
} catch (Exception $e) {
    $conn = null;
    $db_connection_error = $e->getMessage();
}

/**
 * Get active mysqli connection, throwing exception if DB not fully ready
 */
function get_db_connection() {
    global $conn, $db_connection_error;
    if (!$conn) {
        throw new Exception("Database connection is not established. Error: " . $db_connection_error);
    }
    if (!@$conn->select_db(DB_NAME)) {
        throw new Exception("Database '" . DB_NAME . "' could not be selected. Error: " . $conn->error);
    }
    return $conn;
}
