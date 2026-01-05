<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];
$username = $_SESSION["username"];

$success_message = "";
$error_message = "";

// Get current user settings
$settings = [
    'email_notifications' => 0,
    'daily_report' => 0,
    'voltage_alerts' => 1,
    'current_alerts' => 1,
    'frequency_alerts' => 1,
    'report_email' => '',
    'alert_threshold_voltage' => 10,
    'alert_threshold_current' => 20,
    'alert_threshold_frequency' => 0.5
];

$settings_sql = "SELECT * FROM user_settings WHERE user_id = ?";
if($stmt = $mysqli->prepare($settings_sql)){
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if($row = $result->fetch_assoc()){
        $settings = array_merge($settings, $row);
    }
    $stmt->close();
}

// Handle form submission
if($_SERVER["REQUEST_METHOD"] == "POST"){
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $daily_report = isset($_POST['daily_report']) ? 1 : 0;
    $voltage_alerts = isset($_POST['voltage_alerts']) ? 1 : 0;
    $current_alerts = isset($_POST['current_alerts']) ? 1 : 0;
    $frequency_alerts = isset($_POST['frequency_alerts']) ? 1 : 0;
    $report_email = filter_var($_POST['report_email'], FILTER_SANITIZE_EMAIL);
    $alert_threshold_voltage = floatval($_POST['alert_threshold_voltage']);
    $alert_threshold_current = floatval($_POST['alert_threshold_current']);
    $alert_threshold_frequency = floatval($_POST['alert_threshold_frequency']);
    
    // Validate email if notifications are enabled
    if($email_notifications && !empty($report_email) && !filter_var($report_email, FILTER_VALIDATE_EMAIL)){
        $error_message = "Please enter a valid email address.";
    } else {
        // Check if settings exist
        $check_sql = "SELECT id FROM user_settings WHERE user_id = ?";
        if($stmt = $mysqli->prepare($check_sql)){
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows > 0){
                // Update existing settings
                $update_sql = "UPDATE user_settings SET 
                    email_notifications = ?,
                    daily_report = ?,
                    voltage_alerts = ?,
                    current_alerts = ?,
                    frequency_alerts = ?,
                    report_email = ?,
                    alert_threshold_voltage = ?,
                    alert_threshold_current = ?,
                    alert_threshold_frequency = ?,
                    updated_at = NOW()
                    WHERE user_id = ?";
                
                if($update_stmt = $mysqli->prepare($update_sql)){
                    $update_stmt->bind_param("iiiiisdddi", 
                        $email_notifications, $daily_report, $voltage_alerts, 
                        $current_alerts, $frequency_alerts, $report_email,
                        $alert_threshold_voltage, $alert_threshold_current, 
                        $alert_threshold_frequency, $user_id);
                    
                    if($update_stmt->execute()){
                        $success_message = "Settings updated successfully!";
                        $settings = array_merge($settings, [
                            'email_notifications' => $email_notifications,
                            'daily_report' => $daily_report,
                            'voltage_alerts' => $voltage_alerts,
                            'current_alerts' => $current_alerts,
                            'frequency_alerts' => $frequency_alerts,
                            'report_email' => $report_email,
                            'alert_threshold_voltage' => $alert_threshold_voltage,
                            'alert_threshold_current' => $alert_threshold_current,
                            'alert_threshold_frequency' => $alert_threshold_frequency
                        ]);
                    } else {
                        $error_message = "Error updating settings.";
                    }
                    $update_stmt->close();
                }
            } else {
                // Insert new settings
                $insert_sql = "INSERT INTO user_settings (
                    user_id, email_notifications, daily_report, 
                    voltage_alerts, current_alerts, frequency_alerts,
                    report_email, alert_threshold_voltage, 
                    alert_threshold_current, alert_threshold_frequency
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                if($insert_stmt = $mysqli->prepare($insert_sql)){
                    $insert_stmt->bind_param("iiiiisdddd", 
                        $user_id, $email_notifications, $daily_report, 
                        $voltage_alerts, $current_alerts, $frequency_alerts,
                        $report_email, $alert_threshold_voltage, 
                        $alert_threshold_current, $alert_threshold_frequency);
                    
                    if($insert_stmt->execute()){
                        $success_message = "Settings saved successfully!";
                        $settings = array_merge($settings, [
                            'email_notifications' => $email_notifications,
                            'daily_report' => $daily_report,
                            'voltage_alerts' => $voltage_alerts,
                            'current_alerts' => $current_alerts,
                            'frequency_alerts' => $frequency_alerts,
                            'report_email' => $report_email,
                            'alert_threshold_voltage' => $alert_threshold_voltage,
                            'alert_threshold_current' => $alert_threshold_current,
                            'alert_threshold_frequency' => $alert_threshold_frequency
                        ]);
                    } else {
                        $error_message = "Error saving settings.";
                    }
                    $insert_stmt->close();
                }
            }
            $stmt->close();
        }
    }
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Electrical Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
            --card-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), #1a252f);
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        
        .main-container {
            padding: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            border-left: 5px solid var(--secondary-color);
        }
        
        .settings-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }
        
        .section-title {
            color: var(--primary-color);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-bg);
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .section-title i {
            margin-right: 10px;
            color: var(--secondary-color);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark-text);
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 16px;
            width: 100%;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }
        
        .form-check {
            margin-bottom: 15px;
        }
        
        .form-check-input {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            cursor: pointer;
        }
        
        .form-check-input:checked {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .form-check-label {
            cursor: pointer;
            font-weight: 500;
            color: var(--dark-text);
        }
        
        .alert {
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-success {
            background-color: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }
        
        .alert-danger {
            background-color: #fdedec;
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }
        
        .btn-save {
            background: linear-gradient(135deg, var(--success-color), #219653);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
            background: linear-gradient(135deg, #219653, #1e8449);
        }
        
        .settings-section {
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid #eee;
        }
        
        .settings-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .threshold-input {
            max-width: 200px;
        }
        
        .threshold-unit {
            margin-left: 10px;
            color: #666;
            font-weight: 500;
        }
        
        .info-text {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        
        .email-test-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .btn-test {
            background: linear-gradient(135deg, var(--warning-color), #e67e22);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-test:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
        }
        
        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
            }
            
            .threshold-input {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-bolt me-2"></i>Electrical Load Monitor
            </a>
            <div class="d-flex align-items-center">
                <span class="text-light me-3"><i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($username); ?></span>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="main-container">
        <!-- Header Card -->
        <div class="header-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1"><i class="fas fa-cog me-2"></i>System Settings</h2>
                    <p class="text-muted mb-0">Configure your monitoring preferences and notifications</p>
                </div>
            </div>
        </div>
        
        <?php if($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if($error_message): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Settings Form -->
        <div class="settings-card">
            <form method="POST" action="">
                
                <!-- Notification Settings -->
                <div class="settings-section">
                    <h4 class="section-title"><i class="fas fa-bell"></i> Notification Settings</h4>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="email_notifications" id="email_notifications" 
                                   value="1" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="email_notifications">
                                Enable Email Notifications
                            </label>
                        </div>
                        <div class="info-text">Receive email alerts for critical events</div>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="daily_report" id="daily_report" 
                                   value="1" <?php echo $settings['daily_report'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="daily_report">
                                Send Daily Report
                            </label>
                        </div>
                        <div class="info-text">Receive daily summary report via email</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Report Email Address</label>
                        <input type="email" class="form-control" name="report_email" id="report_email"
                               value="<?php echo htmlspecialchars($settings['report_email']); ?>"
                               placeholder="substation.manager@example.com">
                        <div class="info-text">Email address where reports will be sent</div>
                    </div>
                </div>
                
                <!-- Alert Settings -->
                <div class="settings-section">
                    <h4 class="section-title"><i class="fas fa-exclamation-triangle"></i> Alert Settings</h4>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="voltage_alerts" id="voltage_alerts" 
                                       value="1" <?php echo $settings['voltage_alerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="voltage_alerts">
                                    Voltage Alerts
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="current_alerts" id="current_alerts" 
                                       value="1" <?php echo $settings['current_alerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="current_alerts">
                                    Current Alerts
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="frequency_alerts" id="frequency_alerts" 
                                       value="1" <?php echo $settings['frequency_alerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="frequency_alerts">
                                    Frequency Alerts
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Threshold Settings -->
                <div class="settings-section">
                    <h4 class="section-title"><i class="fas fa-sliders-h"></i> Threshold Settings</h4>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">Voltage Threshold (%)</label>
                                <div class="d-flex align-items-center">
                                    <input type="number" step="0.1" min="0" max="50" 
                                           class="form-control threshold-input" 
                                           name="alert_threshold_voltage" id="alert_threshold_voltage"
                                           value="<?php echo $settings['alert_threshold_voltage']; ?>">
                                    <span class="threshold-unit">%</span>
                                </div>
                                <div class="info-text">Alert when voltage deviates by this percentage</div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">Current Threshold (%)</label>
                                <div class="d-flex align-items-center">
                                    <input type="number" step="0.1" min="0" max="100" 
                                           class="form-control threshold-input" 
                                           name="alert_threshold_current" id="alert_threshold_current"
                                           value="<?php echo $settings['alert_threshold_current']; ?>">
                                    <span class="threshold-unit">%</span>
                                </div>
                                <div class="info-text">Alert when current exceeds rated value by this percentage</div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">Frequency Threshold (Hz)</label>
                                <div class="d-flex align-items-center">
                                    <input type="number" step="0.01" min="0" max="5" 
                                           class="form-control threshold-input" 
                                           name="alert_threshold_frequency" id="alert_threshold_frequency"
                                           value="<?php echo $settings['alert_threshold_frequency']; ?>">
                                    <span class="threshold-unit">Hz</span>
                                </div>
                                <div class="info-text">Alert when frequency deviates from 50Hz by this value</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Save Button -->
                <div class="d-flex justify-content-between align-items-center">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    
                    <a href="reports.php" class="btn btn-primary">
                        <i class="fas fa-file-alt me-2"></i>Generate Report
                    </a>
                </div>
                
                <!-- Test Email Section -->
                <div class="email-test-section mt-4">
                    <h5><i class="fas fa-envelope me-2"></i>Test Email Configuration</h5>
                    <p class="mb-3">Send a test email to verify your email settings are working correctly.</p>
                    <button type="button" class="btn-test" onclick="sendTestEmail()">
                        <i class="fas fa-paper-plane me-2"></i>Send Test Email
                    </button>
                    <div id="testEmailResult" class="mt-3" style="display: none;"></div>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Toggle email field based on checkbox
        const emailCheckbox = document.getElementById('email_notifications');
        const emailField = document.getElementById('report_email');
        
        function toggleEmailField() {
            emailField.disabled = !emailCheckbox.checked;
            if(!emailCheckbox.checked) {
                emailField.value = '';
            }
        }
        
        emailCheckbox.addEventListener('change', toggleEmailField);
        toggleEmailField(); // Initial call
        
        // Test email function
        function sendTestEmail() {
            const email = document.getElementById('report_email').value;
            
            if(!email) {
                alert('Please enter an email address first.');
                return;
            }
            
            const resultDiv = document.getElementById('testEmailResult');
            resultDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin me-2"></i>Sending test email...</div>';
            resultDiv.style.display = 'block';
            
            // Send AJAX request
            fetch('send-test-email.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'email=' + encodeURIComponent(email)
            })
            .then(response => response.text())
            .then(data => {
                resultDiv.innerHTML = data;
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Error sending test email: ' + error + '</div>';
            });
        }
        
        // Validate threshold inputs
        document.querySelectorAll('.threshold-input').forEach(input => {
            input.addEventListener('change', function() {
                const value = parseFloat(this.value);
                const max = parseFloat(this.max);
                const min = parseFloat(this.min);
                
                if(value < min) this.value = min;
                if(value > max) this.value = max;
            });
        });
    </script>
</body>
</html>