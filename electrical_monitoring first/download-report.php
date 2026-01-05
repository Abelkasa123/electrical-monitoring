<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

if(!isset($_GET['id']) || empty($_GET['id'])){
    die("Report ID required");
}

$report_id = (int)$_GET['id'];
$user_id = $_SESSION["id"];

// Verify ownership and get report
$sql = "SELECT * FROM reports WHERE id = ? AND user_id = ? AND status = 'completed'";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("ii", $report_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows == 0){
    die("Report not found or not available for download");
}

$report = $result->fetch_assoc();
$stmt->close();

// Get the report data
$report_data = json_decode($report['parameters'], true);
$report_type = $report['report_type'];
$start_date = $report['start_date'];
$end_date = $report['end_date'];

// Generate Excel file for download
$filename = "electrical_report_" . $start_date . "_to_" . $end_date . ".xls";

// Set headers for Excel download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: max-age=0");

// Start output
echo "<html>";
echo "<head>";
echo "<style>
    table { border-collapse: collapse; width: 100%; }
    th { background-color: #4CAF50; color: white; padding: 8px; border: 1px solid #ddd; }
    td { padding: 8px; border: 1px solid #ddd; text-align: right; }
    tr:nth-child(even) { background-color: #f2f2f2; }
    .summary { background-color: #e8f4f8; }
    .header { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 20px; }
</style>";
echo "</head>";
echo "<body>";

// Report header
echo "<div class='header'>Electrical Load Monitoring Report</div>";
echo "<p><strong>Report ID:</strong> #" . $report['id'] . "</p>";
echo "<p><strong>Report Type:</strong> " . ucfirst($report_type) . "</p>";
echo "<p><strong>Date Range:</strong> " . $start_date . " to " . $end_date . "</p>";
echo "<p><strong>Generated On:</strong> " . date('Y-m-d H:i:s', strtotime($report['generated_at'])) . "</p>";
echo "<hr>";

// Summary statistics
if(isset($report_data['summary']) && !empty($report_data['summary'])){
    echo "<h3>Summary Statistics</h3>";
    echo "<table border='1'>";
    echo "<tr><th>Parameter</th><th>Value</th></tr>";
    
    $summary = $report_data['summary'];
    echo "<tr><td>Total Records</td><td>" . ($summary['total_records'] ?? 0) . "</td></tr>";
    echo "<tr><td>Min Voltage</td><td>" . ($summary['min_voltage'] ?? 0) . " V</td></tr>";
    echo "<tr><td>Max Voltage</td><td>" . ($summary['max_voltage'] ?? 0) . " V</td></tr>";
    echo "<tr><td>Avg Voltage</td><td>" . round(($summary['avg_voltage'] ?? 0), 2) . " V</td></tr>";
    echo "<tr><td>Min Current</td><td>" . ($summary['min_current'] ?? 0) . " A</td></tr>";
    echo "<tr><td>Max Current</td><td>" . ($summary['max_current'] ?? 0) . " A</td></tr>";
    echo "<tr><td>Avg Current</td><td>" . round(($summary['avg_current'] ?? 0), 2) . " A</td></tr>";
    echo "<tr><td>Min Active Power</td><td>" . ($summary['min_power'] ?? 0) . " W</td></tr>";
    echo "<tr><td>Max Active Power</td><td>" . ($summary['max_power'] ?? 0) . " W</td></tr>";
    echo "<tr><td>Avg Active Power</td><td>" . round(($summary['avg_power'] ?? 0), 2) . " W</td></tr>";
    echo "<tr><td>Total Energy</td><td>" . round(($summary['total_power'] ?? 0), 2) . " Wh</td></tr>";
    echo "<tr><td>Min Frequency</td><td>" . ($summary['min_frequency'] ?? 0) . " Hz</td></tr>";
    echo "<tr><td>Max Frequency</td><td>" . ($summary['max_frequency'] ?? 0) . " Hz</td></tr>";
    echo "<tr><td>Avg Frequency</td><td>" . round(($summary['avg_frequency'] ?? 0), 2) . " Hz</td></tr>";
    
    echo "</table><br>";
}

// Raw data
if(isset($report_data['raw_data']) && !empty($report_data['raw_data'])){
    echo "<h3>Raw Data (Sample)</h3>";
    echo "<table border='1'>";
    echo "<tr>
        <th>Timestamp</th>
        <th>Voltage (V)</th>
        <th>Current (A)</th>
        <th>Active Power (W)</th>
        <th>Reactive Power (VAR)</th>
        <th>Frequency (Hz)</th>
        <th>Power Factor</th>
    </tr>";
    
    foreach($report_data['raw_data'] as $record){
        echo "<tr>";
        echo "<td>" . $record['timestamp'] . "</td>";
        echo "<td>" . $record['voltage'] . "</td>";
        echo "<td>" . $record['current'] . "</td>";
        echo "<td>" . $record['active_power'] . "</td>";
        echo "<td>" . $record['reactive_power'] . "</td>";
        echo "<td>" . $record['frequency'] . "</td>";
        echo "<td>" . $record['power_factor'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table><br>";
}

// Hourly averages
if(isset($report_data['charts']['hourly']) && !empty($report_data['charts']['hourly'])){
    echo "<h3>Hourly Averages</h3>";
    echo "<table border='1'>";
    echo "<tr>
        <th>Hour</th>
        <th>Avg Voltage (V)</th>
        <th>Avg Current (A)</th>
        <th>Avg Power (W)</th>
    </tr>";
    
    foreach($report_data['charts']['hourly'] as $hourly){
        echo "<tr>";
        echo "<td>" . $hourly['hour'] . ":00</td>";
        echo "<td>" . round($hourly['avg_voltage'], 2) . "</td>";
        echo "<td>" . round($hourly['avg_current'], 2) . "</td>";
        echo "<td>" . round($hourly['avg_power'], 2) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
}

echo "<hr>";
echo "<p style='font-size: 12px; color: #666;'>Generated by Electrical Monitoring System</p>";
echo "</body>";
echo "</html>";

// Log the download activity
$log_sql = "INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'download_report', ?)";
$log_stmt = $mysqli->prepare($log_sql);
$details = "Downloaded report #" . $report_id;
$log_stmt->bind_param("is", $user_id, $details);
$log_stmt->execute();
$log_stmt->close();

$mysqli->close();
exit;
?>