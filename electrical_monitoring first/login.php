<?php
session_start();
require_once "config/database.php";

$username = $password = "";
$username_err = $password_err = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter username.";
    } else{
        $username = trim($_POST["username"]);
    }
    
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    if(empty($username_err) && empty($password_err)){
        // Updated SQL to include role and other user info
        $sql = "SELECT id, username, password, full_name, email, role FROM users WHERE username = ?";
        
        if($stmt = $mysqli->prepare($sql)){
            $stmt->bind_param("s", $param_username);
            $param_username = $username;
            
            if($stmt->execute()){
                $stmt->store_result();
                
                if($stmt->num_rows == 1){
                    $stmt->bind_result($id, $username, $hashed_password, $full_name, $email, $role);
                    if($stmt->fetch()){
                        if(password_verify($password, $hashed_password)){
                            // Check if user is active
                            $status_sql = "SELECT status FROM users WHERE id = ?";
                            $status_stmt = $mysqli->prepare($status_sql);
                            $status_stmt->bind_param("i", $id);
                            $status_stmt->execute();
                            $status_stmt->bind_result($user_status);
                            $status_stmt->fetch();
                            $status_stmt->close();
                            
                            if($user_status !== 'active'){
                                $username_err = "Account is not active. Please contact administrator.";
                            } else {
                                // Start session and set user data
                                $_SESSION["loggedin"] = true;
                                $_SESSION["id"] = $id;
                                $_SESSION["username"] = $username;
                                $_SESSION["full_name"] = $full_name;
                                $_SESSION["email"] = $email;
                                $_SESSION["role"] = $role; // Store role in session
                                
                                // Update last login time
                                $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                                $update_stmt = $mysqli->prepare($update_sql);
                                $update_stmt->bind_param("i", $id);
                                $update_stmt->execute();
                                $update_stmt->close();
                                
                                // Log login activity
                                $log_sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, ip_address, user_agent) 
                                          VALUES (?, 'login', 'users', ?, ?, ?)";
                                $log_stmt = $mysqli->prepare($log_sql);
                                $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
                                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                                $log_stmt->bind_param("iiss", $id, $id, $ip_address, $user_agent);
                                $log_stmt->execute();
                                $log_stmt->close();
                                
                                // Redirect based on role
                                header("location: dashboard.php");
                                exit;
                            }
                        } else{
                            $password_err = "Invalid password.";
                        }
                    }
                } else{
                    $username_err = "No account found.";
                }
            } else{
                echo "Something went wrong. Please try again later.";
            }
            $stmt->close();
        }
    }
    $mysqli->close();
}

// Check for registration success message
if(isset($_SESSION['registration_success'])) {
    $registration_success = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Electrical Monitoring</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            padding: 40px;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-container h2 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 30px;
            font-weight: 600;
        }
        
        .login-container h2 i {
            color: #3498db;
            margin-right: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            color: #495057;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 500;
            transition: all 0.3s;
            width: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: #6c757d;
        }
        
        .login-footer a {
            color: #3498db;
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        .role-badges {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .role-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .badge-admin { background-color: #dc3545; color: white; }
        .badge-manager { background-color: #ffc107; color: black; }
        .badge-operator { background-color: #28a745; color: white; }
        
        .demo-login {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #3498db;
        }
        
        .demo-login h6 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .demo-btn-group {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .demo-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 5px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .demo-btn:hover {
            transform: translateY(-2px);
        }
        
        .demo-admin { background: #dc3545; color: white; }
        .demo-manager { background: #ffc107; color: black; }
        .demo-operator { background: #28a745; color: white; }
        
        .invalid-feedback {
            display: block;
            margin-top: 5px;
            font-size: 0.875rem;
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2><i class="fas fa-bolt"></i> Electrical Monitoring System</h2>
        
        <!-- Registration Success Message -->
        <?php if(isset($registration_success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo $registration_success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Error Messages -->
        <?php if(!empty($username_err) || !empty($password_err)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo !empty($username_err) ? $username_err : $password_err; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label><i class="fas fa-user me-2"></i>Username</label>
                <input type="text" name="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" 
                       value="<?php echo htmlspecialchars($username); ?>" placeholder="Enter your username">
                <?php if(!empty($username_err)): ?>
                    <span class="invalid-feedback"><?php echo $username_err; ?></span>
                <?php endif; ?>
            </div>    
            
            <div class="form-group">
                <label><i class="fas fa-lock me-2"></i>Password</label>
                <input type="password" name="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" 
                       placeholder="Enter your password">
                <?php if(!empty($password_err)): ?>
                    <span class="invalid-feedback"><?php echo $password_err; ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <input type="submit" class="btn btn-primary" value="Login">
            </div>
            
            <!-- Demo Login Buttons -->
            <div class="demo-login">
                <h6><i class="fas fa-vial me-2"></i>Demo Accounts</h6>
                <div class="demo-btn-group">
                    <button type="button" class="demo-btn demo-admin" onclick="setDemoLogin('admin')">
                        <i class="fas fa-user-shield me-1"></i> Admin
                    </button>
                    <button type="button" class="demo-btn demo-manager" onclick="setDemoLogin('manager')">
                        <i class="fas fa-user-tie me-1"></i> Manager
                    </button>
                    <button type="button" class="demo-btn demo-operator" onclick="setDemoLogin('operator')">
                        <i class="fas fa-user-cog me-1"></i> Operator
                    </button>
                </div>
            </div>
            
            <div class="login-footer">
                <p>No account? <a href="register.php">Register here</a></p>
                <div class="role-badges">
                    <span class="role-badge badge-admin">Admin</span>
                    <span class="role-badge badge-manager">Manager</span>
                    <span class="role-badge badge-operator">Operator</span>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        // Demo login function
        function setDemoLogin(role) {
            const credentials = {
                'admin': {username: 'admin', password: 'admin123'},
                'manager': {username: 'manager', password: 'manager123'},
                'operator': {username: 'operator', password: 'operator123'}
            };
            
            document.querySelector('input[name="username"]').value = credentials[role].username;
            document.querySelector('input[name="password"]').value = credentials[role].password;
            
            // Show notification
            showNotification(`Demo ${role} credentials loaded`, 'info');
        }
        
        // Show notification
        function showNotification(message, type) {
            // Remove existing notification
            const existing = document.querySelector('.demo-notification');
            if(existing) existing.remove();
            
            // Create notification
            const notification = document.createElement('div');
            notification.className = `demo-notification alert alert-${type} position-fixed`;
            notification.style.cssText = `
                top: 20px;
                right: 20px;
                z-index: 9999;
                min-width: 200px;
                animation: fadeIn 0.3s;
            `;
            notification.innerHTML = `
                <i class="fas fa-${type === 'info' ? 'info-circle' : 'check-circle'} me-2"></i>
                ${message}
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 2 seconds
            setTimeout(() => {
                notification.style.animation = 'fadeOut 0.3s';
                setTimeout(() => notification.remove(), 300);
            }, 2000);
        }
        
        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes fadeOut {
                from { opacity: 1; transform: translateY(0); }
                to { opacity: 0; transform: translateY(-10px); }
            }
        `;
        document.head.appendChild(style);
        
        // Auto-focus username field
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="username"]').focus();
        });
    </script>
</body>
</html>