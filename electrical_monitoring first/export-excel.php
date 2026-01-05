<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'excel';
$columns = $_GET['columns'] ?? ['voltage', 'current', 'active_power', 'reactive_power', 'frequency', 'power_factor'];

$user_id = $_SESSION["id"];

// Build query based on filters
$where_conditions = ["user_id = ?", "DATE(timestamp) BETWEEN ? AND ?"];
$params = [$user_id, $start_date, $end_date];
$param_types = "iss";

// Build column list
$column_list = "timestamp";
if(in_array('voltage', $columns)) $column_list .= ", voltage";
if(in_array('current', $columns)) $column_list .= ", current";
if(in_array('active_power', $columns)) $column_list .= ", active_power";
if(in_array('reactive_power', $columns)) $column_list .= ", reactive_power";
if(in_array('frequency', $columns)) $column_list .= ", frequency";
if(in_array('power_factor', $columns)) $column_list .= ", power_factor";

$sql = "SELECT $column_list FROM electrical_data 
        WHERE " . implode(" AND ", $where_conditions) . " 
        ORDER BY timestamp DESC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Set headers based on format
if($format == 'csv') {
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=electrical_data_$start_date" . "_to_$end_date.csv");
    $output = fopen('php://output', 'w');
    
    // Write headers
    $headers = ['Timestamp'];
    if(in_array('voltage', $columns)) $headers[] = 'Voltage (V)';
    if(in_array('current', $columns)) $headers[] = 'Current (A)';
    if(in_array('active_power', $columns)) $headers[] = 'Active Power (W)';
    if(in_array('reactive_power', $columns)) $headers[] = 'Reactive Power (VAR)';
    if(in_array('frequency', $columns)) $headers[] = 'Frequency (Hz)';
    if(in_array('power_factor', $columns)) $headers[] = 'Power Factor';
    fputcsv($output, $headers);
    
    // Write data
    while($row = $result->fetch_assoc()) {
        $data = [$row['timestamp']];
        if(in_array('voltage', $columns)) $data[] = $row['voltage'];
        if(in_array('current', $columns)) $data[] = $row['current'];
        if(in_array('active_power', $columns)) $data[] = $row['active_power'];
        if(in_array('reactive_power', $columns)) $data[] = $row['reactive_power'];
        if(in_array('frequency', $columns)) $data[] = $row['frequency'];
        if(in_array('power_factor', $columns)) $data[] = $row['power_factor'];
        fputcsv($output, $data);
    }
    
    fclose($output);
} else {
    // Default to Excel format
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=electrical_data_$start_date" . "_to_$end_date.xls");
    
    echo "<html>";
    echo "<head>";
    echo "<style>
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #4CAF50; color: white; padding: 8px; border: 1px solid #ddd; }
        td { padding: 8px; border: 1px solid #ddd; text-align: right; }
        tr:nth-child(even) { background-color: #f2f2f2; }
    </style>";
    echo "</head>";
    echo "<body>";
    
    echo "<h2>Electrical Load Data Report</h2>";
    echo "<p>Date Range: $start_date to $end_date</p>";
    echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";
    
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Timestamp</th>";
    if(in_array('voltage', $columns)) echo "<th>Voltage (V)</th>";
    if(in_array('current', $columns)) echo "<th>Current (A)</th>";
    if(in_array('active_power', $columns)) echo "<th>Active Power (W)</th>";
    if(in_array('reactive_power', $columns)) echo "<th>Reactive Power (VAR)</th>";
    if(in_array('frequency', $columns)) echo "<th>Frequency (Hz)</th>";
    if(in_array('power_factor', $columns)) echo "<th>Power Factor</th>";
    echo "</tr>";
    
    while($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['timestamp'] . "</td>";
        if(in_array('voltage', $columns)) echo "<td>" . $row['voltage'] . "</td>";
        if(in_array('current', $columns)) echo "<td>" . $row['current'] . "</td>";
        if(in_array('active_power', $columns)) echo "<td>" . $row['active_power'] . "</td>";
        if(in_array('reactive_power', $columns)) echo "<td>" . $row['reactive_power'] . "</td>";
        if(in_array('frequency', $columns)) echo "<td>" . $row['frequency'] . "</td>";
        if(in_array('power_factor', $columns)) echo "<td>" . $row['power_factor'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    echo "</body></html>";
}

$stmt->close();
$mysqli->close();
?>