<?php
// permissions.php - Role-based permission system

class Permissions {
    private $db;
    private $user_id;
    private $role;
    private $permissions = [];
    
    public function __construct($db, $user_id) {
        $this->db = $db;
        $this->user_id = $user_id;
        $this->loadUserPermissions();
    }
    
    private function loadUserPermissions() {
        // Get user role and permissions
        $sql = "SELECT u.role, r.permissions 
                FROM users u 
                LEFT JOIN user_roles r ON u.role = r.role_name 
                WHERE u.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if($row = $result->fetch_assoc()) {
            $this->role = $row['role'];
            if($row['permissions']) {
                $this->permissions = json_decode($row['permissions'], true);
            } else {
                $this->loadDefaultPermissions($row['role']);
            }
        }
        $stmt->close();
    }
    
    private function loadDefaultPermissions($role) {
        // Default permissions for each role
        $default_permissions = [
            'admin' => [
                'dashboard' => true,
                'data_entry' => true,
                'view_data' => true,
                'edit_data' => true,
                'delete_data' => true,
                'reports' => true,
                'summaries' => true,
                'import_export' => true,
                'user_management' => true,
                'settings' => true,
                'approve_reject' => true,
                'view_all_data' => true,
                'export_all' => true,
                'system_config' => true
            ],
            'manager' => [
                'dashboard' => true,
                'data_entry' => false,
                'view_data' => true,
                'edit_data' => false,
                'delete_data' => false,
                'reports' => true,
                'summaries' => true,
                'import_export' => false,
                'user_management' => false,
                'settings' => false,
                'approve_reject' => true,
                'view_all_data' => true,
                'export_all' => true,
                'system_config' => false
            ],
            'operator' => [
                'dashboard' => true,
                'data_entry' => true,
                'view_data' => true,
                'edit_data' => true,
                'delete_data' => true,
                'reports' => false,
                'summaries' => false,
                'import_export' => false,
                'user_management' => false,
                'settings' => false,
                'approve_reject' => false,
                'view_all_data' => false, // Can only view own data
                'export_all' => false,
                'system_config' => false
            ]
        ];
        
        $this->permissions = $default_permissions[$role] ?? [];
    }
    
    public function hasPermission($permission) {
        return isset($this->permissions[$permission]) && $this->permissions[$permission] === true;
    }
    
    public function getRole() {
        return $this->role;
    }
    
    public function getAllPermissions() {
        return $this->permissions;
    }
    
    public function canEditRecord($record_user_id) {
        // Admin can edit any record
        if($this->role === 'admin') return true;
        
        // Manager cannot edit records
        if($this->role === 'manager') return false;
        
        // Operator can only edit their own records
        if($this->role === 'operator') {
            return $this->user_id == $record_user_id;
        }
        
        return false;
    }
    
    public function canDeleteRecord($record_user_id) {
        // Admin can delete any record
        if($this->role === 'admin') return true;
        
        // Manager cannot delete records
        if($this->role === 'manager') return false;
        
        // Operator can only delete their own records
        if($this->role === 'operator') {
            return $this->user_id == $record_user_id;
        }
        
        return false;
    }
    
    public function canApproveRecord() {
        return $this->role === 'admin' || $this->role === 'manager';
    }
    
    public function requirePermission($permission) {
        if(!$this->hasPermission($permission)) {
            header("location: access-denied.php");
            exit;
        }
    }
    
    public function logAudit($action, $table_name, $record_id = null, $old_values = null, $new_values = null) {
        $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $old_json = $old_values ? json_encode($old_values) : null;
        $new_json = $new_values ? json_encode($new_values) : null;
        
        $stmt->bind_param("issiisss", 
            $this->user_id, $action, $table_name, $record_id, 
            $old_json, $new_json, $ip, $agent
        );
        
        $stmt->execute();
        $stmt->close();
    }
}
?>