<?php
session_start();

/**
 * Check if user is logged in
 * Redirect to login page if not authenticated
 */
function require_login() {
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        header("location: ../login.php");
        exit;
    }
}

/**
 * Check if user is already logged in
 * Redirect to dashboard if already authenticated
 */
function redirect_if_logged_in() {
    if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
        header("location: ../dashboard.php");
        exit;
    }
}

/**
 * Get current user ID
 */
function get_current_user_id() {
    return isset($_SESSION["id"]) ? $_SESSION["id"] : null;
}

/**
 * Get current username
 */
function get_current_username() {
    return isset($_SESSION["username"]) ? $_SESSION["username"] : 'Guest';
}

/**
 * Logout and destroy session
 */
function logout() {
    $_SESSION = array();
    session_destroy();
    header("location: ../login.php");
    exit;
}

/**
 * Check user permissions (for future role-based access)
 */
function has_permission($permission) {
    // Add role-based logic here if needed
    return isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true;
}
?>