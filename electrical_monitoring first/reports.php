<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];
$error_msg = $success_msg = "";
$reports = [];

// Function to check and fix database tables
function initializeDatabaseTables() {
    global $mysqli;
    
    // 1. Check and create activity_logs table with proper structure
    $check_activity = $mysqli->query("SHOW TABLES LIKE 'activity_logs'");
    if($check_activity->num_rows == 0) {
        // Create activity_logs table
        $sql = "CREATE TABLE activity_logs (
            id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_action (user_id, action)
        ) ENGINE=InnoDB";
        $mysqli->query($sql);
    } else {
        // Check if ip_address column exists
        $check_column = $mysqli->query("SHOW COLUMNS FROM activity_logs LIKE 'ip_address'");
        if($check_column->num_rows == 0) {
            // Add ip_address column if it doesn't exist
            $mysqli->query("ALTER TABLE activity_logs ADD COLUMN ip_address VARCHAR(45) AFTER details");
        }
        
        // Check if user_agent column exists
        $check_agent = $mysqli->query("SHOW COLUMNS FROM activity_logs LIKE 'user_agent'");
        if($check_agent->num_rows == 0) {
            // Add user_agent column if it doesn't exist
            $mysqli->query("ALTER TABLE activity_logs ADD COLUMN user_agent TEXT AFTER ip_address");
        }
    }
    
    // 2. Check and create user_settings table
    $check_settings = $mysqli->query("SHOW TABLES LIKE 'user_settings'");
    if($check_settings->num_rows == 0) {
        $sql = "CREATE TABLE user_settings (
            id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            report_email VARCHAR(100),
            report_frequency ENUM('daily', 'weekly', 'monthly') DEFAULT 'daily',
            voltage_min DECIMAL(10,2) DEFAULT 200,
            voltage_max DECIMAL(10,2) DEFAULT 250,
            current_max DECIMAL(10,2) DEFAULT 100,
            frequency_min DECIMAL(5,2) DEFAULT 49,
            frequency_max DECIMAL(5,2) DEFAULT 51,
            alert_threshold INT DEFAULT 90,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user (user_id)
        ) ENGINE=InnoDB";
        $mysqli->query($sql);
    }
    
    // 3. Check and create reports table
    $check_reports = $mysqli->query("SHOW TABLES LIKE 'reports'");
    if($check_reports->num_rows == 0) {
        $sql = "CREATE TABLE reports (
            id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            report_type ENUM('daily', 'weekly', 'monthly', 'custom') NOT NULL,
            report_name VARCHAR(100) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            file_path VARCHAR(255),
            parameters TEXT,
            status ENUM('pending', 'generating', 'completed', 'failed') DEFAULT 'pending',
            INDEX idx_user_type (user_id, report_type),
            INDEX idx_date_range (start_date, end_date)
        ) ENGINE=InnoDB";
        $mysqli->query($sql);
    }
}

// Initialize database tables
initializeDatabaseTables();

// Get user settings
$user_settings = null;
$settings_sql = "SELECT * FROM user_settings WHERE user_id = ? LIMIT 1";
$stmt = $mysqli->prepare($settings_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_settings = $result->fetch_assoc();
$stmt->close();

// If no settings exist, create default
if(!$user_settings) {
    $insert_sql = "INSERT INTO user_settings (user_id, report_email) VALUES (?, ?)";
    $insert_stmt = $mysqli->prepare($insert_sql);
    $user_email = $_SESSION["email"] ?? $_SESSION["username"] . "@example.com";
    $insert_stmt->bind_param("is", $user_id, $user_email);
    $insert_stmt->execute();
    $insert_stmt->close();
    
    $user_settings = ['user_id' => $user_id, 'report_email' => $user_email];
}

// Handle report generation
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_report'])){
    $report_type = $_POST['report_type'] ?? 'daily';
    $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $end_date = $_POST['end_date'] ?? date('Y-m-d');
    $report_name = $_POST['report_name'] ?? "Electrical_Report_" . date('Y-m-d');
    $format = $_POST['format'] ?? 'pdf';
    
    // Validate dates
    if(strtotime($start_date) > strtotime($end_date)){
        $error_msg = "Start date cannot be after end date";
    } else {
        // Insert report into database
        $report_sql = "INSERT INTO reports (user_id, report_type, report_name, start_date, end_date, status) 
                      VALUES (?, ?, ?, ?, ?, 'pending')";
        $report_stmt = $mysqli->prepare($report_sql);
        $report_stmt->bind_param("issss", $user_id, $report_type, $report_name, $start_date, $end_date);
        
        if($report_stmt->execute()){
            $report_id = $mysqli->insert_id;
            
            // Generate report data
            $report_data = generateReportData($user_id, $start_date, $end_date, $report_type);
            
            // Update report with generated data
            $update_sql = "UPDATE reports SET 
                          parameters = ?,
                          status = 'completed',
                          generated_at = NOW()
                          WHERE id = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $params_json = json_encode($report_data);
            $update_stmt->bind_param("si", $params_json, $report_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $success_msg = "Report generated successfully! Report ID: #$report_id";
            
            // Log activity (with safe column handling)
            logActivity($user_id, 'report_generated', "Generated report #$report_id ($report_type)");
            
        } else {
            $error_msg = "Error generating report: " . $report_stmt->error;
        }
        $report_stmt->close();
    }
}

// Fetch user's reports
$reports_sql = "SELECT * FROM reports WHERE user_id = ? ORDER BY generated_at DESC LIMIT 10";
$reports_stmt = $mysqli->prepare($reports_sql);
$reports_stmt->bind_param("i", $user_id);
$reports_stmt->execute();
$reports_result = $reports_stmt->get_result();
$reports = $reports_result->fetch_all(MYSQLI_ASSOC);
$reports_stmt->close();

// Function to generate report data
function generateReportData($user_id, $start_date, $end_date, $report_type) {
    global $mysqli;
    
    $data = [
        'summary' => [],
        'statistics' => [],
        'charts' => [],
        'raw_data' => []
    ];
    
    // Get summary statistics
    $summary_sql = "SELECT 
        COUNT(*) as total_records,
        MIN(voltage) as min_voltage,
        MAX(voltage) as max_voltage,
        AVG(voltage) as avg_voltage,
        MIN(current) as min_current,
        MAX(current) as max_current,
        AVG(current) as avg_current,
        MIN(active_power) as min_power,
        MAX(active_power) as max_power,
        AVG(active_power) as avg_power,
        SUM(active_power) as total_power,
        MIN(frequency) as min_frequency,
        MAX(frequency) as max_frequency,
        AVG(frequency) as avg_frequency
    FROM electrical_data 
    WHERE user_id = ? 
    AND DATE(timestamp) BETWEEN ? AND ?";
    
    $stmt = $mysqli->prepare($summary_sql);
    $stmt->bind_param("iss", $user_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $data['summary'] = $result->fetch_assoc();
    $stmt->close();
    
    // Get hourly averages for chart
    $hourly_sql = "SELECT 
        HOUR(timestamp) as hour,
        AVG(voltage) as avg_voltage,
        AVG(current) as avg_current,
        AVG(active_power) as avg_power
    FROM electrical_data 
    WHERE user_id = ? 
    AND DATE(timestamp) BETWEEN ? AND ?
    GROUP BY HOUR(timestamp)
    ORDER BY hour";
    
    $stmt = $mysqli->prepare($hourly_sql);
    $stmt->bind_param("iss", $user_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $data['charts']['hourly'] = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get recent raw data (limit to 50 records)
    $raw_sql = "SELECT 
        timestamp,
        voltage,
        current,
        active_power,
        reactive_power,
        frequency,
        power_factor
    FROM electrical_data 
    WHERE user_id = ? 
    AND DATE(timestamp) BETWEEN ? AND ?
    ORDER BY timestamp DESC
    LIMIT 50";
    
    $stmt = $mysqli->prepare($raw_sql);
    $stmt->bind_param("iss", $user_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $data['raw_data'] = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $data;
}

// Function to log activity with safe column handling
function logActivity($user_id, $action, $details = '') {
    global $mysqli;
    
    // First check if ip_address column exists
    $check_column = $mysqli->query("SHOW COLUMNS FROM activity_logs LIKE 'ip_address'");
    $has_ip_column = ($check_column->num_rows > 0);
    
    $check_agent = $mysqli->query("SHOW COLUMNS FROM activity_logs LIKE 'user_agent'");
    $has_agent_column = ($check_agent->num_rows > 0);
    
    // Build query based on available columns
    if($has_ip_column && $has_agent_column) {
        $sql = "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt->bind_param("issss", $user_id, $action, $details, $ip, $agent);
    } elseif($has_ip_column) {
        $sql = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                VALUES (?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt->bind_param("isss", $user_id, $action, $details, $ip);
    } else {
        $sql = "INSERT INTO activity_logs (user_id, action, details) 
                VALUES (?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("iss", $user_id, $action, $details);
    }
    
    $stmt->execute();
    $stmt->close();
}

// Get report statistics for display
$stats = [
    'total' => count($reports),
    'completed' => 0,
    'pending' => 0,
    'this_month' => 0
];

foreach($reports as $report) {
    if($report['status'] == 'completed') $stats['completed']++;
    if($report['status'] == 'pending') $stats['pending']++;
    if(date('Y-m', strtotime($report['generated_at'])) == date('Y-m')) $stats['this_month']++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Electrical Monitoring</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
        }
        
        .report-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-generating { background-color: #cce5ff; color: #004085; }
        .status-completed { background-color: #d4edda; color: #155724; }
        .status-failed { background-color: #f8d7da; color: #721c24; }
        
        .report-type-card {
            border: 2px solid transparent;
            border-radius: 10px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s;
            height: 100%;
        }
        
        .report-type-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .report-type-card.active {
            border-color: var(--secondary-color);
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .report-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .btn-generate {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            font-weight: 500;
            border-radius: 8px;
        }
        
        .btn-generate:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        
        .stat-card {
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .stat-card h3 {
            font-size: 2rem;
            margin-bottom: 5px;
        }
        
        .stat-card small {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
            <div class="container-fluid">
                <a class="navbar-brand" href="dashboard.php">
                    <i class="fas fa-bolt me-2"></i> Electrical Monitor
                </a>
                <div class="d-flex align-items-center">
                    <span class="text-white me-3">Welcome, <?php echo $_SESSION['username']; ?></span>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </nav>
        
        <!-- Navigation -->
        <div class="row">
            <div class="col-md-2 bg-light min-vh-100">
                <div class="pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="data-entry.php">
                                <i class="fas fa-keyboard me-2"></i> Data Entry
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="view-data.php">
                                <i class="fas fa-database me-2"></i> View Data
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="reports.php">
                                <i class="fas fa-chart-pie me-2"></i> Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="summaries.php">
                                <i class="fas fa-chart-bar me-2"></i> 24H Summary
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="import-export.php">
                                <i class="fas fa-file-import me-2"></i> Import/Export
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            
            <div class="col-md-10">
                <div class="p-4">
                    <!-- Page Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2><i class="fas fa-chart-pie text-primary me-2"></i> Reports & Analytics</h2>
                        <button type="button" class="btn btn-outline-primary" onclick="exportAllReports()">
                            <i class="fas fa-download me-1"></i> Export All
                        </button>
                    </div>
                    
                    <!-- Success/Error Messages -->
                    <?php if($success_msg): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo $success_msg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if($error_msg): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_msg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Quick Stats -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stat-card bg-primary">
                                <h3><?php echo $stats['total']; ?></h3>
                                <small>TOTAL REPORTS</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card bg-success">
                                <h3><?php echo $stats['completed']; ?></h3>
                                <small>COMPLETED</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card bg-warning">
                                <h3><?php echo $stats['pending']; ?></h3>
                                <small>PENDING</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card bg-info">
                                <h3><?php echo $stats['this_month']; ?></h3>
                                <small>THIS MONTH</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Report Generation Section -->
                    <div class="report-card">
                        <h4 class="mb-4"><i class="fas fa-plus-circle text-primary me-2"></i> Generate New Report</h4>
                        
                        <form method="POST" action="" id="reportForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Report Type</label>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <div class="report-type-card text-center <?php echo ($_POST['report_type'] ?? 'daily') == 'daily' ? 'active' : ''; ?>"
                                                 onclick="selectReportType('daily')">
                                                <i class="fas fa-calendar-day report-icon text-primary"></i>
                                                <h6>Daily</h6>
                                                <small class="text-muted">24-hour summary</small>
                                                <input type="radio" name="report_type" value="daily" 
                                                       <?php echo ($_POST['report_type'] ?? 'daily') == 'daily' ? 'checked' : ''; ?> 
                                                       style="display: none;">
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="report-type-card text-center <?php echo ($_POST['report_type'] ?? 'daily') == 'weekly' ? 'active' : ''; ?>"
                                                 onclick="selectReportType('weekly')">
                                                <i class="fas fa-calendar-week report-icon text-success"></i>
                                                <h6>Weekly</h6>
                                                <small class="text-muted">7-day analysis</small>
                                                <input type="radio" name="report_type" value="weekly"
                                                       <?php echo ($_POST['report_type'] ?? 'daily') == 'weekly' ? 'checked' : ''; ?> 
                                                       style="display: none;">
                                            </div>
                                        </div>
                                        <div class="col-6 mt-2">
                                            <div class="report-type-card text-center <?php echo ($_POST['report_type'] ?? 'daily') == 'monthly' ? 'active' : ''; ?>"
                                                 onclick="selectReportType('monthly')">
                                                <i class="fas fa-calendar-alt report-icon text-warning"></i>
                                                <h6>Monthly</h6>
                                                <small class="text-muted">30-day overview</small>
                                                <input type="radio" name="report_type" value="monthly"
                                                       <?php echo ($_POST['report_type'] ?? 'daily') == 'monthly' ? 'checked' : ''; ?> 
                                                       style="display: none;">
                                            </div>
                                        </div>
                                        <div class="col-6 mt-2">
                                            <div class="report-type-card text-center <?php echo ($_POST['report_type'] ?? 'daily') == 'custom' ? 'active' : ''; ?>"
                                                 onclick="selectReportType('custom')">
                                                <i class="fas fa-cogs report-icon text-info"></i>
                                                <h6>Custom</h6>
                                                <small class="text-muted">Select date range</small>
                                                <input type="radio" name="report_type" value="custom"
                                                       <?php echo ($_POST['report_type'] ?? 'daily') == 'custom' ? 'checked' : ''; ?> 
                                                       style="display: none;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-8">
                                    <div class="row g-3">
                                        <!-- Report Name -->
                                        <div class="col-12">
                                            <label class="form-label fw-bold">Report Name</label>
                                            <input type="text" class="form-control" name="report_name" 
                                                   value="<?php echo $_POST['report_name'] ?? 'Electrical_Report_' . date('Y-m-d'); ?>"
                                                   placeholder="Enter report name" required>
                                        </div>
                                        
                                        <!-- Date Range -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Start Date</label>
                                            <input type="date" class="form-control" name="start_date" id="startDate"
                                                   value="<?php echo $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days')); ?>"
                                                   required>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">End Date</label>
                                            <input type="date" class="form-control" name="end_date" id="endDate"
                                                   value="<?php echo $_POST['end_date'] ?? date('Y-m-d'); ?>"
                                                   required>
                                        </div>
                                        
                                        <!-- Report Format -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Output Format</label>
                                            <select class="form-select" name="format">
                                                <option value="pdf">PDF Document</option>
                                                <option value="excel">Excel Spreadsheet</option>
                                                <option value="csv">CSV File</option>
                                                <option value="html">HTML Report</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Include Options -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Include</label>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="include_charts" checked>
                                                <label class="form-check-label">Charts & Graphs</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="include_summary" checked>
                                                <label class="form-check-label">Statistical Summary</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="include_raw" checked>
                                                <label class="form-check-label">Raw Data</label>
                                            </div>
                                        </div>
                                        
                                        <!-- Generate Button -->
                                        <div class="col-12 mt-3">
                                            <button type="submit" name="generate_report" class="btn btn-generate">
                                                <i class="fas fa-play-circle me-2"></i> Generate Report
                                            </button>
                                            
                                            <button type="button" class="btn btn-outline-primary ms-2" onclick="previewReport()">
                                                <i class="fas fa-eye me-2"></i> Preview
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Recent Reports -->
                    <div class="report-card">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4 class="mb-0"><i class="fas fa-history text-primary me-2"></i> Recent Reports</h4>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="filterReports('all')">
                                    All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="filterReports('completed')">
                                    Completed
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="filterReports('pending')">
                                    Pending
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Report ID</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Date Range</th>
                                        <th>Generated</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="reportsTableBody">
                                    <?php if(count($reports) > 0): ?>
                                        <?php foreach($reports as $report): ?>
                                        <tr>
                                            <td>#<?php echo $report['id']; ?></td>
                                            <td><?php echo htmlspecialchars($report['report_name']); ?></td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo ucfirst($report['report_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($report['start_date'])); ?> 
                                                to 
                                                <?php echo date('M d, Y', strtotime($report['end_date'])); ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if($report['generated_at']) {
                                                    echo date('Y-m-d H:i', strtotime($report['generated_at']));
                                                } else {
                                                    echo '<span class="text-muted">Not generated</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                $status_class = '';
                                                switch($report['status']){
                                                    case 'completed': $status_class = 'status-completed'; break;
                                                    case 'generating': $status_class = 'status-generating'; break;
                                                    case 'pending': $status_class = 'status-pending'; break;
                                                    case 'failed': $status_class = 'status-failed'; break;
                                                }
                                                ?>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo ucfirst($report['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <?php if($report['status'] == 'completed'): ?>
                                                    <button class="btn btn-outline-success" 
                                                            onclick="downloadReport(<?php echo $report['id']; ?>)"
                                                            title="Download">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    
                                                    <button class="btn btn-outline-info" 
                                                            onclick="viewReport(<?php echo $report['id']; ?>)"
                                                            title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <button class="btn btn-outline-danger" 
                                                            onclick="deleteReport(<?php echo $report['id']; ?>)"
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fas fa-file-alt fa-3x mb-3"></i>
                                                    <h5>No reports generated yet</h5>
                                                    <p>Generate your first report using the form above.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select report type
        function selectReportType(type) {
            // Remove active class from all cards
            document.querySelectorAll('.report-type-card').forEach(card => {
                card.classList.remove('active');
            });
            
            // Add active class to selected card
            event.currentTarget.classList.add('active');
            
            // Update radio button
            document.querySelector(`input[name="report_type"][value="${type}"]`).checked = true;
            
            // Set default dates based on type
            const endDate = new Date();
            const startDate = new Date();
            
            switch(type) {
                case 'daily':
                    startDate.setDate(endDate.getDate() - 1);
                    break;
                case 'weekly':
                    startDate.setDate(endDate.getDate() - 7);
                    break;
                case 'monthly':
                    startDate.setMonth(endDate.getMonth() - 1);
                    break;
                case 'custom':
                    // Keep existing dates
                    return;
            }
            
            // Format dates as YYYY-MM-DD
            const formatDate = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };
            
            document.getElementById('startDate').value = formatDate(startDate);
            document.getElementById('endDate').value = formatDate(endDate);
        }
        
        // Preview report
        function previewReport() {
            const form = document.getElementById('reportForm');
            const formData = new FormData(form);
            
            // Show loading
            showNotification('Generating preview...', 'info');
            
            // Simulate preview generation
            setTimeout(() => {
                showNotification('Preview feature coming soon!', 'info');
            }, 1000);
        }
        
        // Download report
        function downloadReport(reportId) {
            window.location.href = `download-report.php?id=${reportId}`;
        }
        
        // View report
        function viewReport(reportId) {
            window.location.href = `view-report.php?id=${reportId}`;
        }
        
        // Delete report
        function deleteReport(reportId) {
            if(confirm('Are you sure you want to delete this report?')) {
                fetch('delete-report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'id=' + reportId
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        showNotification('Report deleted successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Network error. Please try again.', 'error');
                });
            }
        }
        
        // Export all reports
        function exportAllReports() {
            showNotification('Exporting all reports...', 'info');
            window.location.href = 'export-all-reports.php';
        }
        
        // Filter reports
        function filterReports(status) {
            const rows = document.querySelectorAll('#reportsTableBody tr');
            rows.forEach(row => {
                if(status === 'all') {
                    row.style.display = '';
                } else {
                    const statusCell = row.querySelector('.status-badge');
                    if(statusCell && statusCell.textContent.toLowerCase().includes(status)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
        }
        
        // Notification function
        function showNotification(message, type) {
            const alertClass = {
                'success': 'alert-success',
                'error': 'alert-danger',
                'warning': 'alert-warning',
                'info': 'alert-info'
            }[type];
            
            const icon = {
                'success': 'fa-check-circle',
                'error': 'fa-exclamation-circle',
                'warning': 'fa-exclamation-triangle',
                'info': 'fa-info-circle'
            }[type];
            
            // Create notification
            const notification = document.createElement('div');
            notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
            notification.style.cssText = `
                top: 20px;
                right: 20px;
                z-index: 9999;
                min-width: 300px;
                max-width: 400px;
            `;
            notification.innerHTML = `
                <i class="fas ${icon} me-2"></i> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove
            setTimeout(() => {
                if(notification.parentNode) {
                    notification.classList.remove('show');
                    setTimeout(() => notification.remove(), 300);
                }
            }, 3000);
        }
    </script>
</body>
</html>

<?php $mysqli->close(); ?>