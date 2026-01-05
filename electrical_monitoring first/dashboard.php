<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];
$username = $_SESSION["username"];

// Helper functions for voltage categories
function detectVoltageCategory($voltage) {
    if($voltage >= 380000) {
        return "EHV (400kV)";
    } elseif($voltage >= 220000) {
        return "HV (230kV)";
    } elseif($voltage >= 11000) {
        return "MV (15kV)";
    } else {
        return "LV";
    }
}

function getVoltageStatus($voltage, $category = null) {
    if($category === null) {
        $category = detectVoltageCategory($voltage);
    }
    
    switch($category) {
        case 'EHV (400kV)':
            // 400kV ±5% tolerance
            if($voltage >= 380000 && $voltage <= 420000) return "NORMAL";
            if(($voltage >= 370000 && $voltage < 380000) || ($voltage > 420000 && $voltage <= 430000)) return "WARNING";
            return "CRITICAL";
            
        case 'HV (230kV)':
            // 230kV ±5% tolerance
            if($voltage >= 218500 && $voltage <= 241500) return "NORMAL";
            if(($voltage >= 207000 && $voltage < 218500) || ($voltage > 241500 && $voltage <= 253000)) return "WARNING";
            return "CRITICAL";
            
        case 'MV (15kV)':
            // 15kV ±5% tolerance
            if($voltage >= 14250 && $voltage <= 15750) return "NORMAL";
            if(($voltage >= 13500 && $voltage < 14250) || ($voltage > 15750 && $voltage <= 16500)) return "WARNING";
            return "CRITICAL";
            
        case 'LV':
            // LV: 220-240V single phase, 380-415V three phase
            if($voltage >= 380 && $voltage <= 415) {
                // Three-phase
                return "NORMAL";
            } elseif($voltage >= 220 && $voltage <= 240) {
                // Single-phase
                return "NORMAL";
            } elseif(($voltage >= 360 && $voltage < 380) || ($voltage > 415 && $voltage <= 435) ||
                     ($voltage >= 210 && $voltage < 220) || ($voltage > 240 && $voltage <= 250)) {
                return "WARNING";
            }
            return "CRITICAL";
            
        default:
            return "WARNING";
    }
}

// Get latest reading
$latest_sql = "SELECT * FROM electrical_data WHERE user_id = ? ORDER BY timestamp DESC LIMIT 1";
$latest_reading = null;
if($stmt = $mysqli->prepare($latest_sql)){
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $latest_reading = $result->fetch_assoc();
    $stmt->close();
}

// Get 24-hour summary
$summary_sql = "SELECT 
    COUNT(*) as total_readings,
    MIN(voltage) as min_voltage,
    MAX(voltage) as max_voltage,
    AVG(voltage) as avg_voltage,
    MIN(current) as min_current,
    MAX(current) as max_current,
    AVG(current) as avg_current,
    MIN(active_power) as min_power,
    MAX(active_power) as max_power,
    AVG(active_power) as avg_power,
    SUM(active_power) as total_energy_kwh,
    MIN(frequency) as min_freq,
    MAX(frequency) as max_freq,
    AVG(frequency) as avg_freq
FROM electrical_data 
WHERE user_id = ? 
AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";

$summary_data = null;
if($stmt = $mysqli->prepare($summary_sql)){
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary_data = $result->fetch_assoc();
    $stmt->close();
}

// Get recent readings for table
$recent_sql = "SELECT * FROM electrical_data WHERE user_id = ? ORDER BY timestamp DESC LIMIT 10";
$recent_readings = [];
if($stmt = $mysqli->prepare($recent_sql)){
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()){
        $recent_readings[] = $row;
    }
    $stmt->close();
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Electrical Load Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
            --light-text: #7f8c8d;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: var(--dark-text);
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), #1a252f);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .sidebar {
            background: white;
            min-height: calc(100vh - 56px);
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
            padding: 0;
        }
        
        .sidebar .nav-link {
            color: var(--dark-text);
            padding: 15px 25px;
            border-left: 4px solid transparent;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link:hover {
            background-color: var(--light-bg);
            color: var(--secondary-color);
            border-left-color: var(--secondary-color);
        }
        
        .sidebar .nav-link.active {
            background-color: var(--light-bg);
            color: var(--secondary-color);
            border-left-color: var(--secondary-color);
            font-weight: 600;
        }
        
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        
        .main-content {
            padding: 30px;
        }
        
        .welcome-card {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.2);
        }
        
        .welcome-card h3 {
            font-weight: 300;
        }
        
        .welcome-card .date-time {
            background: rgba(255,255,255,0.1);
            padding: 10px 15px;
            border-radius: 10px;
            display: inline-block;
            margin-top: 10px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 5px solid var(--secondary-color);
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .stat-card.voltage { border-left-color: #9b59b6; }
        .stat-card.current { border-left-color: #e74c3c; }
        .stat-card.power { border-left-color: #27ae60; }
        .stat-card.frequency { border-left-color: #f39c12; }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .stat-icon.voltage { background-color: rgba(155, 89, 182, 0.1); color: #9b59b6; }
        .stat-icon.current { background-color: rgba(231, 76, 60, 0.1); color: #e74c3c; }
        .stat-icon.power { background-color: rgba(39, 174, 96, 0.1); color: #27ae60; }
        .stat-icon.frequency { background-color: rgba(243, 156, 18, 0.1); color: #f39c12; }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--light-text);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-unit {
            font-size: 14px;
            color: var(--light-text);
            margin-left: 5px;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            margin-top: 30px;
        }
        
        .table-title {
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-bg);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn-custom {
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-custom:hover {
            background: #2980b9;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .quick-actions {
            margin-top: 30px;
        }
        
        .action-btn {
            background: white;
            border: 2px dashed #ddd;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            color: var(--dark-text);
            display: block;
            text-decoration: none;
            height: 100%;
        }
        
        .action-btn:hover {
            border-color: var(--secondary-color);
            background: var(--light-bg);
            color: var(--secondary-color);
            text-decoration: none;
        }
        
        .action-icon {
            font-size: 32px;
            margin-bottom: 10px;
            color: var(--secondary-color);
        }
        
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-normal { background-color: #d5f4e6; color: #27ae60; }
        .badge-warning { background-color: #fef5e7; color: #f39c12; }
        .badge-danger { background-color: #fdedec; color: #e74c3c; }
        
        .summary-item {
            background: var(--light-bg);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        
        .summary-label {
            font-size: 14px;
            color: var(--light-text);
            margin-bottom: 5px;
        }
        
        .summary-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        footer {
            background: var(--primary-color);
            color: white;
            padding: 20px;
            margin-top: 40px;
            border-radius: 12px 12px 0 0;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                margin-bottom: 20px;
            }
            
            .main-content {
                padding: 15px;
            }
            
            .stat-card {
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-bolt me-2"></i>Electrical Load Monitor
            </a>
            <div class="d-flex align-items-center">
                <span class="text-light me-3">Welcome, <?php echo htmlspecialchars($username); ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-2 col-md-3 sidebar">
                <div class="d-flex flex-column flex-shrink-0 p-3">
                    <ul class="nav nav-pills flex-column mb-auto">
                        <li class="nav-item">
                            <a href="dashboard.php" class="nav-link active">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="data-entry.php" class="nav-link">
                                <i class="fas fa-plus-circle"></i> Add Reading
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="view-data.php" class="nav-link">
                                <i class="fas fa-database"></i> View Data
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="summaries.php" class="nav-link">
                                <i class="fas fa-chart-bar"></i> Load Summary
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="import-export.php" class="nav-link">
                                <i class="fas fa-file-excel"></i> Import/Export
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="reports.php" class="nav-link">
                                <i class="fas fa-chart-line"></i> Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="settings.php" class="nav-link">
                                <i class="fas fa-cog"></i> Settings
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-10 col-md-9 main-content">
                <!-- Welcome Card -->
                <div class="welcome-card">
                    <h3><i class="fas fa-user-circle me-2"></i>Welcome back, <?php echo htmlspecialchars($username); ?>!</h3>
                    <p>Monitor and analyze your electrical load data in real-time.</p>
                    <div class="date-time">
                        <i class="fas fa-calendar-alt me-2"></i>
                        <?php echo date('l, F j, Y'); ?> | 
                        <i class="fas fa-clock ms-3 me-2"></i>
                        <span id="live-time"><?php echo date('h:i:s A'); ?></span>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card voltage">
                            <div class="stat-icon voltage">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <div class="stat-value">
                                <?php 
                                echo $latest_reading ? number_format($latest_reading['voltage'], 2) : '0.00'; 
                                ?>
                                <span class="stat-unit">V</span>
                            </div>
                            <div class="stat-label">Voltage</div>
                            <?php if($latest_reading): ?>
                            <div class="mt-2">
                                <?php 
                                $voltage = $latest_reading['voltage'];
                                $category = detectVoltageCategory($voltage);
                                $status = getVoltageStatus($voltage, $category);
                                
                                // Map status to CSS class names
                                $status_map = [
                                    'NORMAL' => 'normal',
                                    'WARNING' => 'warning', 
                                    'CRITICAL' => 'danger',
                                    'STABLE' => 'normal',
                                    'UNSTABLE' => 'warning'
                                ];
                                
                                $status_class = isset($status_map[$status]) ? $status_map[$status] : 'warning';
                                ?>
                                <span class="badge-status badge-<?php echo $status_class; ?>">
                                    <?php echo $category . ' - ' . ucfirst(strtolower($status)); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card current">
                            <div class="stat-icon current">
                                <i class="fas fa-tachometer-alt"></i>
                            </div>
                            <div class="stat-value">
                                <?php 
                                echo $latest_reading ? number_format($latest_reading['current'], 2) : '0.00'; 
                                ?>
                                <span class="stat-unit">A</span>
                            </div>
                            <div class="stat-label">Current</div>
                            <?php if($latest_reading): ?>
                            <div class="mt-2">
                                <?php 
                                $current = $latest_reading['current'];
                                $status = ($current <= 100) ? 'normal' : 
                                          (($current > 100 && $current <= 150) ? 'warning' : 'danger');
                                ?>
                                <span class="badge-status badge-<?php echo $status; ?>">
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card power">
                            <div class="stat-icon power">
                                <i class="fas fa-charging-station"></i>
                            </div>
                            <div class="stat-value">
                                <?php 
                                echo $latest_reading ? number_format($latest_reading['active_power'], 2) : '0.00'; 
                                ?>
                                <span class="stat-unit">W</span>
                            </div>
                            <div class="stat-label">Active Power</div>
                            <?php if($latest_reading): ?>
                            <div class="mt-2">
                                <?php 
                                $power = $latest_reading['active_power'];
                                $status = ($power <= 5000) ? 'normal' : 
                                          (($power > 5000 && $power <= 10000) ? 'warning' : 'danger');
                                ?>
                                <span class="badge-status badge-<?php echo $status; ?>">
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card frequency">
                            <div class="stat-icon frequency">
                                <i class="fas fa-wave-square"></i>
                            </div>
                            <div class="stat-value">
                                <?php 
                                echo $latest_reading ? number_format($latest_reading['frequency'], 2) : '0.00'; 
                                ?>
                                <span class="stat-unit">Hz</span>
                            </div>
                            <div class="stat-label">Frequency</div>
                            <?php if($latest_reading): ?>
                            <div class="mt-2">
                                <?php 
                                $freq = $latest_reading['frequency'];
                                $status = ($freq >= 49.8 && $freq <= 50.2) ? 'normal' : 
                                          ((($freq >= 49.5 && $freq < 49.8) || ($freq > 50.2 && $freq <= 50.5)) ? 'warning' : 'danger');
                                ?>
                                <span class="badge-status badge-<?php echo $status; ?>">
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 24-Hour Summary -->
                <div class="row mt-4">
                    <div class="col-lg-12">
                        <div class="table-container">
                            <h4 class="table-title">
                                <i class="fas fa-chart-line me-2"></i>24-Hour Load Summary
                                <a href="summaries.php" class="btn btn-custom btn-sm">
                                    <i class="fas fa-external-link-alt me-1"></i>View Detailed Summary
                                </a>
                            </h4>
                            <?php if($summary_data): ?>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="summary-item">
                                        <div class="summary-label">Total Readings</div>
                                        <div class="summary-value"><?php echo $summary_data['total_readings']; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-item">
                                        <div class="summary-label">Avg Voltage</div>
                                        <div class="summary-value"><?php echo number_format($summary_data['avg_voltage'], 2); ?> V</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-item">
                                        <div class="summary-label">Avg Current</div>
                                        <div class="summary-value"><?php echo number_format($summary_data['avg_current'], 2); ?> A</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-item">
                                        <div class="summary-label">Total Energy</div>
                                        <div class="summary-value"><?php echo number_format($summary_data['total_energy_kwh'] / 1000, 2); ?> kWh</div>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-database fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No data available for the last 24 hours.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Readings Table -->
                <div class="row mt-4">
                    <div class="col-lg-12">
                        <div class="table-container">
                            <h4 class="table-title">
                                <i class="fas fa-history me-2"></i>Recent Readings
                                <a href="view-data.php" class="btn btn-custom btn-sm">
                                    <i class="fas fa-list me-1"></i>View All Data
                                </a>
                            </h4>
                            <?php if(!empty($recent_readings)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="recentTable">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Voltage (V)</th>
                                            <th>Current (A)</th>
                                            <th>Active Power (W)</th>
                                            <th>Reactive Power (VAR)</th>
                                            <th>Frequency (Hz)</th>
                                            <th>Power Factor</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent_readings as $reading): ?>
                                        <tr>
                                            <td><?php echo date('Y-m-d H:i:s', strtotime($reading['timestamp'])); ?></td>
                                            <td><?php echo number_format($reading['voltage'], 2); ?></td>
                                            <td><?php echo number_format($reading['current'], 2); ?></td>
                                            <td><?php echo number_format($reading['active_power'], 2); ?></td>
                                            <td><?php echo number_format($reading['reactive_power'], 2); ?></td>
                                            <td><?php echo number_format($reading['frequency'], 2); ?></td>
                                            <td><?php echo number_format($reading['power_factor'], 3); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No readings found. Start by adding your first reading.</p>
                                <a href="data-entry.php" class="btn btn-custom mt-2">
                                    <i class="fas fa-plus-circle me-1"></i>Add First Reading
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row quick-actions">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <a href="data-entry.php" class="action-btn">
                            <div class="action-icon">
                                <i class="fas fa-plus-circle"></i>
                            </div>
                            <h5>Add New Reading</h5>
                            <p class="text-muted">Manually enter electrical parameters</p>
                        </a>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <a href="import-export.php" class="action-btn">
                            <div class="action-icon">
                                <i class="fas fa-file-import"></i>
                            </div>
                            <h5>Import Data</h5>
                            <p class="text-muted">Upload Excel/CSV files</p>
                        </a>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <a href="export-excel.php" class="action-btn">
                            <div class="action-icon">
                                <i class="fas fa-file-export"></i>
                            </div>
                            <h5>Export Data</h5>
                            <p class="text-muted">Download data in Excel format</p>
                        </a>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <a href="summaries.php" class="action-btn">
                            <div class="action-icon">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <h5>Generate Report</h5>
                            <p class="text-muted">Detailed load analysis</p>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="text-center">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Electrical Load Monitoring System | Version 1.0</p>
            <p class="mb-0">
                <span class="me-3"><i class="fas fa-user me-1"></i> User: <?php echo htmlspecialchars($username); ?></span>
                <span><i class="fas fa-database me-1"></i> Total Readings: <?php echo $summary_data ? $summary_data['total_readings'] : '0'; ?></span>
            </p>
        </div>
    </footer>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // Live Time Update
        function updateTime() {
            const now = new Date();
            const options = { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: true 
            };
            const timeString = now.toLocaleTimeString('en-US', options);
            document.getElementById('live-time').textContent = timeString;
        }
        setInterval(updateTime, 1000);
        updateTime(); // Initial call

        // Initialize DataTable
        $(document).ready(function() {
            $('#recentTable').DataTable({
                "pageLength": 5,
                "order": [[0, 'desc']],
                "responsive": true
            });
        });

        // Auto-refresh dashboard every 60 seconds
        setTimeout(function() {
            window.location.reload();
        }, 60000);

        // Add animation to stat cards
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 100}ms`;
                card.classList.add('animate__animated', 'animate__fadeInUp');
            });
        });
    </script>
</body>
</html>