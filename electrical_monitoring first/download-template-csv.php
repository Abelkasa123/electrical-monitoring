<?php
// Force download of CSV template
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="electrical_data_template.csv"');
header('Cache-Control: max-age=0');

// Create file pointer
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Column headers
$headers = [
    'Timestamp',
    'Voltage (V)',
    'Current (A)',
    'Active Power (W)',
    'Reactive Power (VAR)',
    'Frequency (Hz)',
    'Power Factor',
    'Voltage Category'
];

// Write headers
fputcsv($output, $headers);

// Add sample data
$sampleData = [
    ['2023-12-15 08:00:00', '230.50', '15.25', '3512.75', '1250.30', '50.00', '0.95', 'Low Voltage'],
    ['2023-12-15 09:00:00', '231.00', '14.80', '3418.80', '1105.60', '49.98', '0.96', 'Low Voltage'],
    ['2023-12-15 10:00:00', '229.75', '16.50', '3790.88', '1420.45', '50.02', '0.93', 'Medium Voltage'],
    ['', '220.00', '10.00', '2200.00', '', '', '', 'Low Voltage'] // Example with optional fields
];

foreach($sampleData as $row){
    fputcsv($output, $row);
}

fclose($output);
exit;