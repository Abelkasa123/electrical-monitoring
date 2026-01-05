<?php
require_once "includes/auth.php";
requireLogin();
require_once "config/database.php";

$user_id = $_SESSION['id'];

// Get summary by voltage category
$summary_sql = "SELECT 
    voltage_category,
    COUNT(*) as count,
    ROUND(MIN(voltage)/1000, 2) as min_kv,
    ROUND(MAX(voltage)/1000, 2) as max_kv,
    ROUND(AVG(voltage)/1000, 2) as avg_kv,
    ROUND(SUM(active_power)/1000000, 2) as total_mw,
    SUM(CASE WHEN status = 'critical' THEN 1 ELSE 0 END) as critical_count,
    SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) as warning_count
    FROM electrical_data 
    WHERE user_id = ? 
    AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY voltage_category 
    ORDER BY FIELD(voltage_category, 'EHV', 'HV', 'MV', 'LV')";

$stmt = $mysqli->prepare($summary_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get recent alarms
$alarms_sql = "SELECT a.*, e.timestamp as data_time 
              FROM alarms a 
              LEFT JOIN electrical_data e ON a.data_id = e.id 
              WHERE a.user_id = ? 
              AND a.acknowledged = FALSE 
              ORDER BY a.created_at DESC 
              LIMIT 10";
$stmt = $mysqli->prepare($alarms_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$alarms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>HV Analysis</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container">
        <?php include 'includes/navbar.php'; ?>
        
        <h2><i class="fas fa-chart-bar"></i> High Voltage Analysis (24 Hours)</h2>
        
        <!-- Summary Cards by Category -->
        <div class="stats-container">
            <?php foreach($summary as $cat): ?>
                <div class="stat-card" style="border-left: 5px solid <?php 
                    echo $cat['voltage_category'] == 'EHV' ? '#F44336' : 
                         ($cat['voltage_category'] == 'HV' ? '#FF9800' : 
                         ($cat['voltage_category'] == 'MV' ? '#2196F3' : '#4CAF50'));
                ?>;">
                    <div class="stat-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $cat['voltage_category']; ?> Voltage</h3>
                        <p class="stat-value"><?php echo $cat['count']; ?> records</p>
                        <p>Range: <?php echo $cat['min_kv']; ?> - <?php echo $cat['max_kv']; ?> kV</p>
                        <p>Avg: <?php echo $cat['avg_kv']; ?> kV | Power: <?php echo $cat['total_mw']; ?> MW</p>
                        <?php if($cat['critical_count'] > 0): ?>
                            <p style="color: #F44336;">
                                <i class="fas fa-exclamation-triangle"></i> 
                                <?php echo $cat['critical_count']; ?> critical
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Alarms Section -->
        <?php if(!empty($alarms)): ?>
            <div class="form-section">
                <h3><i class="fas fa-bell"></i> Active Alarms</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Severity</th>
                            <th>Message</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($alarms as $alarm): ?>
                            <tr>
                                <td><?php echo $alarm['created_at']; ?></td>
                                <td><?php echo str_replace('_', ' ', ucfirst($alarm['alarm_type'])); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $alarm['severity']; ?>">
                                        <?php echo ucfirst($alarm['severity']); ?>
                                    </span>
                                </td>
                                <td><?php echo $alarm['alarm_message']; ?></td>
                                <td>
                                    <button class="btn-action btn-edit" onclick="acknowledgeAlarm(<?php echo $alarm['id']; ?>)">
                                        <i class="fas fa-check"></i> Ack
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <!-- Charts Section -->
        <div class="form-section">
            <h3><i class="fas fa-chart-line"></i> Voltage Distribution</h3>
            <canvas id="voltageChart" width="400" height="200"></canvas>
        </div>
    </div>
    
    <script>
    // Voltage Chart
    const ctx = document.getElementById('voltageChart').getContext('2d');
    const voltageChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($summary, 'voltage_category')); ?>,
            datasets: [{
                label: 'Average Voltage (kV)',
                data: <?php echo json_encode(array_column($summary, 'avg_kv')); ?>,
                backgroundColor: ['#4CAF50', '#2196F3', '#FF9800', '#F44336']
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Voltage (kV)'
                    }
                }
            }
        }
    });
    
    function acknowledgeAlarm(id) {
        if(confirm('Mark this alarm as acknowledged?')) {
            fetch('acknowledge-alarm.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        location.reload();
                    }
                });
        }
    }
    </script>
</body>
</html>
<?php
if(isset($mysqli)){
    $mysqli->close();
}
?>