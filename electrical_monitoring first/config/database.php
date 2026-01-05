<?php
// config/database.php - Single database configuration file

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error reporting (development only - disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define database constants only if not already defined
if (!defined('DB_SERVER')) {
    define('DB_SERVER', 'localhost');
    define('DB_USERNAME', 'root');
    define('DB_PASSWORD', '');
    define('DB_NAME', 'electrical_monitoring');
    
    // Also define alternative names for compatibility
    define('DB_HOST', DB_SERVER);
    define('DB_USER', DB_USERNAME);
    define('DB_PASS', DB_PASSWORD);
}

// Create database connection
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Set charset
$mysqli->set_charset("utf8mb4");

// Set timezone (optional - adjust to your location)
date_default_timezone_set('Asia/Kolkata');
?>