<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];
$username = $_SESSION["username"];

// Get date filter if set
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 day'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$time_range = isset($_GET['time_range']) ? $_GET['time_range'] : '24h';

// Calculate summaries based on selected time range
$where_clause = "user_id = ?";
$params = [$user_id];
$param_types = "i";

switch($time_range) {
    case '24h':
        $where_clause .= " AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $range_label = "Last 24 Hours";
        break;
    case '7d':
        $where_clause .= " AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $range_label = "Last 7 Days";
        break;
    case '30d':
        $where_clause .= " AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $range_label = "Last 30 Days";
        break;
    case 'custom':
        if(!empty($start_date) && !empty($end_date)) {
            $where_clause .= " AND DATE(timestamp) BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
            $param_types .= "ss";
            $range_label = "Custom Range: " . date('M d, Y', strtotime($start_date)) . " to " . date('M d, Y', strtotime($end_date));
        }
        break;
    default:
        $where_clause .= " AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $range_label = "Last 24 Hours";
}

$sql = "SELECT 
    COUNT(*) as total_readings,
    MIN(voltage) as min_voltage,
    MAX(voltage) as max_voltage,
    AVG(voltage) as avg_voltage,
    MIN(current) as min_current,
    MAX(current) as max_current,
    AVG(current) as avg_current,
    MIN(active_power) as min_active_power,
    MAX(active_power) as max_active_power,
    AVG(active_power) as avg_active_power,
    SUM(active_power) as total_active_power,
    MIN(reactive_power) as min_reactive_power,
    MAX(reactive_power) as max_reactive_power,
    AVG(reactive_power) as avg_reactive_power,
    MIN(frequency) as min_frequency,
    MAX(frequency) as max_frequency,
    AVG(frequency) as avg_frequency,
    AVG(power_factor) as avg_power_factor,
    MIN(power_factor) as min_power_factor,
    MAX(power_factor) as max_power_factor
FROM electrical_data 
WHERE $where_clause";

$summary = null;
$chart_data = [];
$hourly_data = [];

if($stmt = $mysqli->prepare($sql)){
    if($param_types == "i") {
        $stmt->bind_param($param_types, $params[0]);
    } else {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $summary = $result->fetch_assoc();
    $stmt->close();
}

// Get hourly data for chart (last 24 hours)
$hourly_sql = "SELECT 
    HOUR(timestamp) as hour,
    AVG(voltage) as avg_voltage,
    AVG(current) as avg_current,
    AVG(active_power) as avg_power,
    AVG(frequency) as avg_frequency,
    COUNT(*) as readings_count
FROM electrical_data 
WHERE user_id = ? 
AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY HOUR(timestamp)
ORDER BY hour";

if($stmt = $mysqli->prepare($hourly_sql)){
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()){
        $hourly_data[] = $row;
    }
    $stmt->close();
}

// Prepare chart data
$chart_labels = [];
$chart_voltage = [];
$chart_current = [];
$chart_power = [];
$chart_frequency = [];

foreach($hourly_data as $data) {
    $chart_labels[] = sprintf("%02d:00", $data['hour']);
    $chart_voltage[] = round($data['avg_voltage'], 2);
    $chart_current[] = round($data['avg_current'], 2);
    $chart_power[] = round($data['avg_power'], 2);
    $chart_frequency[] = round($data['avg_frequency'], 2);
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Load Summary - Electrical Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            max-width: 1400px;
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
        
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.voltage { border-top: 4px solid #9b59b6; }
        .stat-card.current { border-top: 4px solid #e74c3c; }
        .stat-card.power { border-top: 4px solid #27ae60; }
        .stat-card.frequency { border-top: 4px solid #f39c12; }
        .stat-card.power-factor { border-top: 4px solid #3498db; }
        .stat-card.summary { border-top: 4px solid #2c3e50; }
        
        .stat-title {
            font-size: 14px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark-text);
            margin-bottom: 5px;
        }
        
        .stat-unit {
            font-size: 14px;
            color: #95a5a6;
            margin-left: 5px;
        }
        
        .stat-details {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #ecf0f1;
        }
        
        .detail-item {
            text-align: center;
        }
        
        .detail-label {
            font-size: 12px;
            color: #7f8c8d;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark-text);
        }
        
        .detail-value.min { color: #e74c3c; }
        .detail-value.max { color: #27ae60; }
        .detail-value.avg { color: #3498db; }
        
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }
        
        .chart-title {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chart-legend {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            font-size: 14px;
        }
        
        .legend-color {
            width: 15px;
            height: 15px;
            border-radius: 3px;
            margin-right: 8px;
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
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .btn-filter {
            background: #f8f9fa;
            color: var(--dark-text);
            border: 1px solid #dee2e6;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .btn-filter.active {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }
        
        .btn-filter:hover {
            background: #e9ecef;
        }
        
        .btn-filter.active:hover {
            background: #2980b9;
        }
        
        .time-range-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .date-inputs {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .date-input-group {
            flex: 1;
        }
        
        .form-control-date {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 10px;
            width: 100%;
        }
        
        .no-data {
            text-align: center;
            padding: 50px 20px;
            color: #95a5a6;
        }
        
        .no-data i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #bdc3c7;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-export {
            background: #27ae60;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-export.pdf {
            background: #e74c3c;
        }
        
        .btn-export.excel {
            background: #27ae60;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .summary-table {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            box-shadow: var(--card-shadow);
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table th {
            background: #f8f9fa;
            color: var(--primary-color);
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .badge-count {
            background: #3498db;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
            }
            
            .date-inputs {
                flex-direction: column;
                gap: 10px;
            }
            
            .chart-legend {
                flex-wrap: wrap;
            }
            
            .export-buttons {
                flex-direction: column;
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1"><i class="fas fa-chart-bar me-2"></i>Load Summary Analysis</h2>
                    <p class="text-muted mb-0">Detailed analysis of electrical load parameters</p>
                </div>
                <!-- Updated Export Buttons -->
<!-- In summaries.php, add voltage level parameter -->
<script>
function exportData(type) {
    const timeRange = '<?php echo $time_range; ?>';
    const startDate = '<?php echo $start_date; ?>';
    const endDate = '<?php echo $end_date; ?>';
    const voltageLevel = '<?php echo isset($_GET["voltage_level"]) ? $_GET["voltage_level"] : "auto"; ?>';
    
    let url = `export-summary.php?range=${timeRange}&start=${startDate}&end=${endDate}&type=${type}&voltage_level=${voltageLevel}`;
    
    if(timeRange === 'custom' && (!startDate || !endDate)) {
        alert('Please select both start and end dates for custom range export.');
        return;
    }
    
    window.location.href = url;
}
</script>

<!-- Add this JavaScript function -->
<script>
function exportData(type) {
    const timeRange = '<?php echo $time_range; ?>';
    const startDate = '<?php echo $start_date; ?>';
    const endDate = '<?php echo $end_date; ?>';
    
    let url = `export-summary.php?range=${timeRange}&start=${startDate}&end=${endDate}&type=${type}`;
    
    if(timeRange === 'custom' && (!startDate || !endDate)) {
        alert('Please select both start and end dates for custom range export.');
        return;
    }
    
    window.location.href = url;
}
</script>
            </div>
            
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="badge-count">
                        <i class="fas fa-clock me-1"></i> <?php echo $range_label; ?>
                    </span>
                    <?php if($summary && $summary['total_readings'] > 0): ?>
                        <span class="badge-count ms-2">
                            <i class="fas fa-database me-1"></i> <?php echo $summary['total_readings']; ?> Readings
                        </span>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <small class="text-muted">Last Updated: <?php echo date('Y-m-d H:i:s'); ?></small>
                </div>
            </div>
        </div>
        
        <!-- Filter Card -->
        <div class="filter-card">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Time Range</h5>
            
            <!-- Time Range Buttons -->
            <div class="time-range-buttons">
                <a href="?time_range=24h" class="btn-filter <?php echo $time_range == '24h' ? 'active' : ''; ?>">
                    Last 24 Hours
                </a>
                <a href="?time_range=7d" class="btn-filter <?php echo $time_range == '7d' ? 'active' : ''; ?>">
                    Last 7 Days
                </a>
                <a href="?time_range=30d" class="btn-filter <?php echo $time_range == '30d' ? 'active' : ''; ?>">
                    Last 30 Days
                </a>
                <a href="?time_range=custom" class="btn-filter <?php echo $time_range == 'custom' ? 'active' : ''; ?>">
                    Custom Range
                </a>
            </div>
            
            <!-- Custom Date Range -->
            <?php if($time_range == 'custom'): ?>
            <form method="GET" action="" class="mt-3">
                <input type="hidden" name="time_range" value="custom">
                <div class="date-inputs">
                    <div class="date-input-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control-date" 
                               value="<?php echo $start_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="date-input-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control-date" 
                               value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="date-input-group" style="max-width: 100px;">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn-custom" style="width: 100%;">
                            <i class="fas fa-search"></i> Apply
                        </button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
        
        <?php if($summary && $summary['total_readings'] > 0): ?>
        
        <!-- Statistics Cards -->
        <div class="row">
            <!-- Voltage Card -->
            <div class="col-xl-4 col-lg-6 col-md-6">
                <div class="stat-card voltage">
                    <div class="stat-title">
                        <i class="fas fa-bolt me-1"></i> Voltage Analysis
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($summary['avg_voltage'], 2); ?>
                        <span class="stat-unit">V</span>
                    </div>
                    <div class="stat-details">
                        <div class="detail-item">
                            <div class="detail-label">Min</div>
                            <div class="detail-value min"><?php echo number_format($summary['min_voltage'], 2); ?> V</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Max</div>
                            <div class="detail-value max"><?php echo number_format($summary['max_voltage'], 2); ?> V</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Avg</div>
                            <div class="detail-value avg"><?php echo number_format($summary['avg_voltage'], 2); ?> V</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Current Card -->
            <div class="col-xl-4 col-lg-6 col-md-6">
                <div class="stat-card current">
                    <div class="stat-title">
                        <i class="fas fa-tachometer-alt me-1"></i> Current Analysis
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($summary['avg_current'], 2); ?>
                        <span class="stat-unit">A</span>
                    </div>
                    <div class="stat-details">
                        <div class="detail-item">
                            <div class="detail-label">Min</div>
                            <div class="detail-value min"><?php echo number_format($summary['min_current'], 2); ?> A</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Max</div>
                            <div class="detail-value max"><?php echo number_format($summary['max_current'], 2); ?> A</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Avg</div>
                            <div class="detail-value avg"><?php echo number_format($summary['avg_current'], 2); ?> A</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Active Power Card -->
            <div class="col-xl-4 col-lg-6 col-md-6">
                <div class="stat-card power">
                    <div class="stat-title">
                        <i class="fas fa-charging-station me-1"></i> Active Power Analysis
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($summary['avg_active_power'], 2); ?>
                        <span class="stat-unit">W</span>
                    </div>
                    <div class="stat-details">
                        <div class="detail-item">
                            <div class="detail-label">Min</div>
                            <div class="detail-value min"><?php echo number_format($summary['min_active_power'], 2); ?> W</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Max</div>
                            <div class="detail-value max"><?php echo number_format($summary['max_active_power'], 2); ?> W</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Avg</div>
                            <div class="detail-value avg"><?php echo number_format($summary['avg_active_power'], 2); ?> W</div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-bolt me-1"></i>
                            Total Energy: <?php echo number_format($summary['total_active_power'] / 1000, 2); ?> kWh
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Frequency Card -->
            <div class="col-xl-4 col-lg-6 col-md-6">
                <div class="stat-card frequency">
                    <div class="stat-title">
                        <i class="fas fa-wave-square me-1"></i> Frequency Analysis
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($summary['avg_frequency'], 2); ?>
                        <span class="stat-unit">Hz</span>
                    </div>
                    <div class="stat-details">
                        <div class="detail-item">
                            <div class="detail-label">Min</div>
                            <div class="detail-value min"><?php echo number_format($summary['min_frequency'], 2); ?> Hz</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Max</div>
                            <div class="detail-value max"><?php echo number_format($summary['max_frequency'], 2); ?> Hz</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Avg</div>
                            <div class="detail-value avg"><?php echo number_format($summary['avg_frequency'], 2); ?> Hz</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Power Factor Card -->
            <div class="col-xl-4 col-lg-6 col-md-6">
                <div class="stat-card power-factor">
                    <div class="stat-title">
                        <i class="fas fa-percentage me-1"></i> Power Factor Analysis
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($summary['avg_power_factor'], 3); ?>
                    </div>
                    <div class="stat-details">
                        <div class="detail-item">
                            <div class="detail-label">Min</div>
                            <div class="detail-value min"><?php echo number_format($summary['min_power_factor'], 3); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Max</div>
                            <div class="detail-value max"><?php echo number_format($summary['max_power_factor'], 3); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Avg</div>
                            <div class="detail-value avg"><?php echo number_format($summary['avg_power_factor'], 3); ?></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <?php 
                        $pf_status = '';
                        $pf_class = '';
                        if($summary['avg_power_factor'] >= 0.9) {
                            $pf_status = 'Excellent';
                            $pf_class = 'text-success';
                        } elseif($summary['avg_power_factor'] >= 0.8) {
                            $pf_status = 'Good';
                            $pf_class = 'text-primary';
                        } elseif($summary['avg_power_factor'] >= 0.7) {
                            $pf_status = 'Fair';
                            $pf_class = 'text-warning';
                        } else {
                            $pf_status = 'Poor';
                            $pf_class = 'text-danger';
                        }
                        ?>
                        <small class="<?php echo $pf_class; ?>">
                            <i class="fas fa-chart-line me-1"></i>
                            Status: <?php echo $pf_status; ?>
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Summary Card -->
            <div class="col-xl-4 col-lg-6 col-md-6">
                <div class="stat-card summary">
                    <div class="stat-title">
                        <i class="fas fa-chart-pie me-1"></i> Summary Overview
                    </div>
                    <div class="stat-value">
                        <?php echo $summary['total_readings']; ?>
                        <span class="stat-unit">Readings</span>
                    </div>
                    <div class="mt-3">
                        <div class="mb-2">
                            <small class="text-muted">
                                <i class="fas fa-bolt text-warning me-1"></i>
                                Max Power: <?php echo number_format($summary['max_active_power'], 2); ?> W
                            </small>
                        </div>
                        <div class="mb-2">
                            <small class="text-muted">
                                <i class="fas fa-bolt text-success me-1"></i>
                                Avg Power: <?php echo number_format($summary['avg_active_power'], 2); ?> W
                            </small>
                        </div>
                        <div class="mb-2">
                            <small class="text-muted">
                                <i class="fas fa-plug me-1" style="color: #9b59b6;"></i>
                                Avg Voltage: <?php echo number_format($summary['avg_voltage'], 2); ?> V
                            </small>
                        </div>
                        <div class="mb-2">
                            <small class="text-muted">
                                <i class="fas fa-battery-half me-1" style="color: #e74c3c;"></i>
                                Avg Current: <?php echo number_format($summary['avg_current'], 2); ?> A
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Charts Section -->
        <div class="row">
            <div class="col-lg-12">
                <div class="chart-container">
                    <div class="chart-title">
                        <span><i class="fas fa-chart-line me-2"></i>Hourly Average Trends (Last 24 Hours)</span>
                        <select id="chartType" class="form-select" style="width: auto;">
                            <option value="line">Line Chart</option>
                            <option value="bar">Bar Chart</option>
                        </select>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="hourlyChart" height="300"></canvas>
                    </div>
                    <div class="chart-legend">
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #9b59b6;"></div>
                            <span>Voltage (V)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #e74c3c;"></div>
                            <span>Current (A)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #27ae60;"></div>
                            <span>Power (W)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #f39c12;"></div>
                            <span>Frequency (Hz)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Detailed Table -->
        <div class="summary-table">
            <h5 class="mb-4"><i class="fas fa-table me-2"></i>Detailed Summary Table</h5>
            <div class="table-responsive">
                <table class="table table-hover" id="summaryTable">
                    <thead>
                        <tr>
                            <th>Parameter</th>
                            <th>Minimum</th>
                            <th>Maximum</th>
                            <th>Average</th>
                            <th>Unit</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><i class="fas fa-bolt me-2" style="color: #9b59b6;"></i>Voltage</td>
                            <td><?php echo number_format($summary['min_voltage'], 2); ?></td>
                            <td><?php echo number_format($summary['max_voltage'], 2); ?></td>
                            <td><?php echo number_format($summary['avg_voltage'], 2); ?></td>
                            <td>V</td>
                            <td>
                                <?php 
                                $avg_v = $summary['avg_voltage'];
                                if($avg_v >= 220 && $avg_v <= 240) {
                                    echo '<span class="badge bg-success">Normal</span>';
                                } elseif(($avg_v >= 210 && $avg_v < 220) || ($avg_v > 240 && $avg_v <= 250)) {
                                    echo '<span class="badge bg-warning">Warning</span>';
                                } else {
                                    echo '<span class="badge bg-danger">Critical</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-tachometer-alt me-2" style="color: #e74c3c;"></i>Current</td>
                            <td><?php echo number_format($summary['min_current'], 2); ?></td>
                            <td><?php echo number_format($summary['max_current'], 2); ?></td>
                            <td><?php echo number_format($summary['avg_current'], 2); ?></td>
                            <td>A</td>
                            <td>
                                <?php 
                                $avg_a = $summary['avg_current'];
                                if($avg_a <= 100) {
                                    echo '<span class="badge bg-success">Normal</span>';
                                } elseif($avg_a > 100 && $avg_a <= 150) {
                                    echo '<span class="badge bg-warning">Warning</span>';
                                } else {
                                    echo '<span class="badge bg-danger">Critical</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-charging-station me-2" style="color: #27ae60;"></i>Active Power</td>
                            <td><?php echo number_format($summary['min_active_power'], 2); ?></td>
                            <td><?php echo number_format($summary['max_active_power'], 2); ?></td>
                            <td><?php echo number_format($summary['avg_active_power'], 2); ?></td>
                            <td>W</td>
                            <td>
                                <?php 
                                $avg_p = $summary['avg_active_power'];
                                if($avg_p <= 5000) {
                                    echo '<span class="badge bg-success">Normal</span>';
                                } elseif($avg_p > 5000 && $avg_p <= 10000) {
                                    echo '<span class="badge bg-warning">Warning</span>';
                                } else {
                                    echo '<span class="badge bg-danger">Critical</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-exchange-alt me-2" style="color: #3498db;"></i>Reactive Power</td>
                            <td><?php echo number_format($summary['min_reactive_power'], 2); ?></td>
                            <td><?php echo number_format($summary['max_reactive_power'], 2); ?></td>
                            <td><?php echo number_format($summary['avg_reactive_power'], 2); ?></td>
                            <td>VAR</td>
                            <td>
                                <?php 
                                $avg_rp = $summary['avg_reactive_power'];
                                $avg_ap = $summary['avg_active_power'];
                                $ratio = ($avg_ap > 0) ? $avg_rp / $avg_ap : 0;
                                
                                if($ratio <= 0.3) {
                                    echo '<span class="badge bg-success">Good</span>';
                                } elseif($ratio <= 0.5) {
                                    echo '<span class="badge bg-warning">Moderate</span>';
                                } else {
                                    echo '<span class="badge bg-danger">High</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-wave-square me-2" style="color: #f39c12;"></i>Frequency</td>
                            <td><?php echo number_format($summary['min_frequency'], 2); ?></td>
                            <td><?php echo number_format($summary['max_frequency'], 2); ?></td>
                            <td><?php echo number_format($summary['avg_frequency'], 2); ?></td>
                            <td>Hz</td>
                            <td>
                                <?php 
                                $avg_f = $summary['avg_frequency'];
                                if($avg_f >= 49.8 && $avg_f <= 50.2) {
                                    echo '<span class="badge bg-success">Stable</span>';
                                } elseif(($avg_f >= 49.5 && $avg_f < 49.8) || ($avg_f > 50.2 && $avg_f <= 50.5)) {
                                    echo '<span class="badge bg-warning">Unstable</span>';
                                } else {
                                    echo '<span class="badge bg-danger">Critical</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-percentage me-2" style="color: #3498db;"></i>Power Factor</td>
                            <td><?php echo number_format($summary['min_power_factor'], 3); ?></td>
                            <td><?php echo number_format($summary['max_power_factor'], 3); ?></td>
                            <td><?php echo number_format($summary['avg_power_factor'], 3); ?></td>
                            <td>-</td>
                            <td>
                                <?php 
                                $avg_pf = $summary['avg_power_factor'];
                                if($avg_pf >= 0.9) {
                                    echo '<span class="badge bg-success">Excellent</span>';
                                } elseif($avg_pf >= 0.8) {
                                    echo '<span class="badge bg-primary">Good</span>';
                                } elseif($avg_pf >= 0.7) {
                                    echo '<span class="badge bg-warning">Fair</span>';
                                } else {
                                    echo '<span class="badge bg-danger">Poor</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php else: ?>
        
        <!-- No Data Message -->
        <div class="no-data">
            <i class="fas fa-database"></i>
            <h4>No Data Available</h4>
            <p>No electrical readings found for the selected time range.</p>
            <a href="data-entry.php" class="btn-custom mt-3">
                <i class="fas fa-plus-circle me-2"></i>Add First Reading
            </a>
        </div>
        
        <?php endif; ?>
    </div>

    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#summaryTable').DataTable({
                "pageLength": 10,
                "responsive": true,
                "order": [],
                "language": {
                    "search": "Search parameters:",
                    "lengthMenu": "Show _MENU_ entries",
                    "info": "Showing _START_ to _END_ of _TOTAL_ parameters"
                }
            });
        });
        
        // Chart.js Implementation
        let hourlyChart = null;
        
        function initChart(chartType = 'line') {
            const ctx = document.getElementById('hourlyChart').getContext('2d');
            
            // Destroy existing chart if it exists
            if (hourlyChart) {
                hourlyChart.destroy();
            }
            
            const chartData = {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [
                    {
                        label: 'Voltage (V)',
                        data: <?php echo json_encode($chart_voltage); ?>,
                        borderColor: '#9b59b6',
                        backgroundColor: chartType === 'bar' ? '#9b59b6' : 'rgba(155, 89, 182, 0.1)',
                        borderWidth: 2,
                        fill: chartType === 'line',
                        tension: 0.4
                    },
                    {
                        label: 'Current (A)',
                        data: <?php echo json_encode($chart_current); ?>,
                        borderColor: '#e74c3c',
                        backgroundColor: chartType === 'bar' ? '#e74c3c' : 'rgba(231, 76, 60, 0.1)',
                        borderWidth: 2,
                        fill: chartType === 'line',
                        tension: 0.4
                    },
                    {
                        label: 'Power (W)',
                        data: <?php echo json_encode($chart_power); ?>,
                        borderColor: '#27ae60',
                        backgroundColor: chartType === 'bar' ? '#27ae60' : 'rgba(39, 174, 96, 0.1)',
                        borderWidth: 2,
                        fill: chartType === 'line',
                        tension: 0.4
                    },
                    {
                        label: 'Frequency (Hz)',
                        data: <?php echo json_encode($chart_frequency); ?>,
                        borderColor: '#f39c12',
                        backgroundColor: chartType === 'bar' ? '#f39c12' : 'rgba(243, 156, 18, 0.1)',
                        borderWidth: 2,
                        fill: chartType === 'line',
                        tension: 0.4
                    }
                ]
            };
            
            const chartOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toFixed(2);
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: true,
                            color: 'rgba(0,0,0,0.05)'
                        },
                        title: {
                            display: true,
                            text: 'Hour of Day'
                        }
                    },
                    y: {
                        beginAtZero: false,
                        grid: {
                            display: true,
                            color: 'rgba(0,0,0,0.05)'
                        },
                        title: {
                            display: true,
                            text: 'Value'
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'nearest'
                }
            };
            
            hourlyChart = new Chart(ctx, {
                type: chartType,
                data: chartData,
                options: chartOptions
            });
        }
        
        // Initialize chart on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php if(!empty($chart_labels)): ?>
            initChart('line');
            <?php endif; ?>
            
            // Chart type selector
            document.getElementById('chartType').addEventListener('change', function() {
                initChart(this.value);
            });
        });
        
        // Print/PDF Function
        function printSummary() {
            window.print();
        }
        
        // Auto-refresh data every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000); // 5 minutes
        
        // Date validation
        document.addEventListener('DOMContentLoaded', function() {
            const startDate = document.querySelector('input[name="start_date"]');
            const endDate = document.querySelector('input[name="end_date"]');
            
            if (startDate && endDate) {
                startDate.max = '<?php echo date("Y-m-d"); ?>';
                endDate.max = '<?php echo date("Y-m-d"); ?>';
                
                startDate.addEventListener('change', function() {
                    endDate.min = this.value;
                });
                
                endDate.addEventListener('change', function() {
                    startDate.max = this.value;
                });
            }
        });
        
        // Export data function
        function exportData(format) {
            if(format === 'excel') {
                window.location.href = 'export-summary.php?range=<?php echo $time_range; ?>&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>';
            } else if(format === 'pdf') {
                printSummary();
            }
        }
    </script>
</body>
</html>