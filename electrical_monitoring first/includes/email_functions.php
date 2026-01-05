<?php
// Email functions for sending reports

function sendEmailReport($to, $report_data, $report_type, $start_date, $end_date) {
    $subject = getEmailSubject($report_type, $start_date, $end_date);
    $body = generateEmailBody($report_data, $report_type);
    $headers = getEmailHeaders();
    
    // For HTML emails
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    // Send email
    $sent = mail($to, $subject, $body, $headers);
    
    // Log the email attempt
    logEmailAttempt($to, $subject, $sent ? 'sent' : 'failed');
    
    return $sent;
}

function getEmailSubject($report_type, $start_date, $end_date) {
    $date_range = date('M j, Y', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date));
    
    $subjects = [
        'daily' => "Daily Electrical Load Report - " . date('F j, Y'),
        'weekly' => "Weekly Electrical Load Analysis - Week of $date_range",
        'monthly' => "Monthly Electrical Load Report - " . date('F Y'),
        'custom' => "Electrical Load Report - $date_range"
    ];
    
    return $subjects[$report_type] ?? "Electrical Load Report - $date_range";
}

function generateEmailBody($report_data, $report_type) {
    $summary = $report_data['summary'];
    $start_date = $report_data['date_range']['start'];
    $end_date = $report_data['date_range']['end'];
    
    $body = '<!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .summary-box { background: #f8f9fa; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
            .alert-box { background: #fef5e7; border: 1px solid #f39c12; padding: 15px; margin: 15px 0; border-radius: 5px; }
            .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
            .stat-item { text-align: center; padding: 15px; background: white; border: 1px solid #ddd; border-radius: 5px; }
            .stat-value { font-size: 24px; font-weight: bold; color: #2c3e50; }
            .stat-label { font-size: 12px; color: #666; text-transform: uppercase; }
            .footer { background: #ecf0f1; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            th { background: #f8f9fa; padding: 10px; text-align: left; border-bottom: 2px solid #ddd; }
            td { padding: 10px; border-bottom: 1px solid #ddd; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Electrical Load Monitoring Report</h1>
            <p>' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)) . '</p>
        </div>
        
        <div class="content">
            <div class="summary-box">
                <h3>Executive Summary</h3>
                <p>This report summarizes the electrical load parameters for the specified period.</p>
            </div>';
    
    // Add alerts if any
    if($summary['voltage_alerts'] > 0 || $summary['current_alerts'] > 0 || $summary['frequency_alerts'] > 0) {
        $body .= '<div class="alert-box">
            <h3><i class="fas fa-exclamation-triangle"></i> Alerts Summary</h3>
            <p>Total alerts detected during this period: ' . 
            ($summary['voltage_alerts'] + $summary['current_alerts'] + $summary['frequency_alerts']) . '</p>
            <ul>
                <li>Voltage Alerts: ' . $summary['voltage_alerts'] . '</li>
                <li>Current Alerts: ' . $summary['current_alerts'] . '</li>
                <li>Frequency Alerts: ' . $summary['frequency_alerts'] . '</li>
            </ul>
        </div>';
    }
    
    // Key Statistics
    $body .= '<div class="stat-grid">
        <div class="stat-item">
            <div class="stat-value">' . $summary['total_readings'] . '</div>
            <div class="stat-label">Total Readings</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">' . number_format($summary['avg_voltage'], 2) . ' V</div>
            <div class="stat-label">Average Voltage</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">' . number_format($summary['avg_active_power'] / 1000, 2) . ' kW</div>
            <div class="stat-label">Average Power</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">' . number_format($summary['total_active_power'] / 1000000, 2) . ' MWh</div>
            <div class="stat-label">Total Energy</div>
        </div>
    </div>';
    
    // Detailed Statistics Table
    $body .= '<h3>Detailed Statistics</h3>
    <table>
        <tr>
            <th>Parameter</th>
            <th>Minimum</th>
            <th>Maximum</th>
            <th>Average</th>
            <th>Unit</th>
        </tr>
        <tr>
            <td>Voltage</td>
            <td>' . number_format($summary['min_voltage'], 2) . '</td>
            <td>' . number_format($summary['max_voltage'], 2) . '</td>
            <td>' . number_format($summary['avg_voltage'], 2) . '</td>
            <td>V</td>
        </tr>
        <tr>
            <td>Current</td>
            <td>' . number_format($summary['min_current'], 2) . '</td>
            <td>' . number_format($summary['max_current'], 2) . '</td>
            <td>' . number_format($summary['avg_current'], 2) . '</td>
            <td>A</td>
        </tr>
        <tr>
            <td>Active Power</td>
            <td>' . number_format($summary['min_active_power'], 2) . '</td>
            <td>' . number_format($summary['max_active_power'], 2) . '</td>
            <td>' . number_format($summary['avg_active_power'], 2) . '</td>
            <td>W</td>
        </tr>
        <tr>
            <td>Frequency</td>
            <td>' . number_format($summary['min_frequency'], 2) . '</td>
            <td>' . number_format($summary['max_frequency'], 2) . '</td>
            <td>' . number_format($summary['avg_frequency'], 2) . '</td>
            <td>Hz</td>
        </tr>
        <tr>
            <td>Power Factor</td>
            <td>' . number_format($summary['min_power_factor'], 3) . '</td>
            <td>' . number_format($summary['max_power_factor'], 3) . '</td>
            <td>' . number_format($summary['avg_power_factor'], 3) . '</td>
            <td>-</td>
        </tr>
    </table>';
    
    // Recommendations
    $body .= '<div class="summary-box">
        <h3>Recommendations</h3>
        <ul>
            <li>Review any alerts or warnings mentioned above</li>
            <li>Check equipment maintenance schedule</li>
            <li>Monitor peak load times for optimization</li>
            <li>Verify power factor correction if needed</li>
        </ul>
    </div>';
    
    $body .= '</div>
        
        <div class="footer">
            <p>This report was automatically generated by the Electrical Load Monitoring System.</p>
            <p>For any questions or concerns, please contact the system administrator.</p>
            <p>Generated on: ' . date('F j, Y, g:i a') . '</p>
        </div>
    </body>
    </html>';
    
    return $body;
}

function getEmailHeaders() {
    $headers = "From: Electrical Monitoring System <noreply@substation.com>\r\n";
    $headers .= "Reply-To: noreply@substation.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    return $headers;
}

function logEmailAttempt($to, $subject, $status) {
    // Implement email logging here
    // You can save to database or log file
    $log_entry = date('Y-m-d H:i:s') . " - To: $to - Subject: $subject - Status: $status\n";
    file_put_contents('logs/email_log.txt', $log_entry, FILE_APPEND);
}

// Function to send daily automated reports
function sendDailyReports() {
    require_once "config/database.php";
    
    // Get all users with daily reports enabled
    $sql = "SELECT u.id, u.username, us.report_email 
            FROM users u 
            JOIN user_settings us ON u.id = us.user_id 
            WHERE us.daily_report = 1 
            AND us.report_email IS NOT NULL 
            AND us.report_email != ''";
    
    $mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    
    if($result = $mysqli->query($sql)){
        while($user = $result->fetch_assoc()){
            $report_data = generateReportData($mysqli, $user['id'], 
                date('Y-m-d', strtotime('-1 day')), 
                date('Y-m-d'), 
                'daily');
            
            if($report_data['total_readings'] > 0){
                sendEmailReport($user['report_email'], $report_data, 'daily', 
                    date('Y-m-d', strtotime('-1 day')), 
                    date('Y-m-d'));
            }
        }
        $result->close();
    }
    
    $mysqli->close();
}
?>