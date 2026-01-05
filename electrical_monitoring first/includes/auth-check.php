<?php
// auth-check.php - Authentication and role checking
require_once 'permissions.php';

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Initialize permissions
$permissions = new Permissions($mysqli, $_SESSION["id"]);

// Store in session for easy access
$_SESSION['role'] = $permissions->getRole();
$_SESSION['permissions'] = $permissions->getAllPermissions();

// Function to check if user can access a page
function checkAccess($page_permission) {
    global $permissions;
    $permissions->requirePermission($page_permission);
}

// Function to get user role badge
function getRoleBadge($role) {
    $badges = [
        'admin' => '<span class="badge bg-danger">Admin</span>',
        'manager' => '<span class="badge bg-warning">Manager</span>',
        'operator' => '<span class="badge bg-primary">Operator</span>'
    ];
    return $badges[$role] ?? '<span class="badge bg-secondary">User</span>';
}

// Function to check if record requires approval
function requiresApproval($record_id, $mysqli) {
    $sql = "SELECT approval_status FROM electrical_data WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $record_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result->fetch_assoc();
    $stmt->close();
    
    return $record['approval_status'] == 'pending';
}

// Function to get approval status badge
function getApprovalBadge($status) {
    $badges = [
        'approved' => '<span class="badge bg-success">Approved</span>',
        'pending' => '<span class="badge bg-warning">Pending</span>',
        'rejected' => '<span class="badge bg-danger">Rejected</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">Unknown</span>';
}
?>