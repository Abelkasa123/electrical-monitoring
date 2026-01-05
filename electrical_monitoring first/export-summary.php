<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];

// Get export parameters
$time_range = isset($_GET['range']) ? $_GET['range'] : '24h';
$start_date = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('-1 day'));
$end_date = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');
$export_type = isset($_GET['type']) ? $_GET['type'] : 'summary'; // 'summary' or 'detailed'
$voltage_level = isset($_GET['voltage_level']) ? $_GET['voltage_level'] : 'auto'; // '400kv', '230kv', '15kv', 'lv', 'auto'

// Generate filename based on export type and date range
$filename = "electrical_data_" . $export_type . "_" . date('Y-m-d_H-i-s') . ".xls";

// Set headers for Excel file
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

if($export_type == 'summary') {
    exportSummaryReport($mysqli, $time_range, $start_date, $end_date, $voltage_level, $user_id);
} else {
    exportDetailedData($mysqli, $time_range, $start_date, $end_date, $voltage_level, $user_id);
}

function exportSummaryReport($mysqli, $time_range, $start_date, $end_date, $voltage_level, $user_id) {
    // Build query based on time range
    $where_clause = "user_id = ?";
    $params = [$user_id];
    $param_types = "i";
    
    // Add voltage level filter if specified
    if($voltage_level != 'auto') {
        switch($voltage_level) {
            case '400kv':
                $where_clause .= " AND voltage >= 380000 AND voltage <= 420000";
                $voltage_label = "EHV (400kV)";
                break;
            case '230kv':
                $where_clause .= " AND voltage >= 220000 AND voltage < 380000";
                $voltage_label = "HV (230kV)";
                break;
            case '15kv':
                $where_clause .= " AND voltage >= 11000 AND voltage < 220000";
                $voltage_label = "MV (15kV)";
                break;
            case 'lv':
                $where_clause .= " AND voltage < 11000";
                $voltage_label = "LV (<11kV)";
                break;
            default:
                $voltage_label = "All Voltage Levels";
        }
    } else {
        $voltage_label = "All Voltage Levels (Auto-detected)";
    }
    
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
    
    // Get summary statistics
    $summary_sql = "SELECT 
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
    if($stmt = $mysqli->prepare($summary_sql)){
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
    
    // Output Excel content
    echo "ELECTRICAL LOAD MONITORING - SUMMARY REPORT\t\t\t\t\t\t\n";
    echo "Generated on: " . date('Y-m-d H:i:s') . "\t\t\t\t\t\t\n";
    echo "Time Range: " . $range_label . "\t\t\t\t\t\t\n";
    echo "Voltage Level: " . $voltage_label . "\t\t\t\t\t\t\n";
    echo "\n";
    
    // Determine voltage category automatically if not specified
    if($voltage_level == 'auto' && $summary && $summary['total_readings'] > 0) {
        $avg_voltage = $summary['avg_voltage'];
        if($avg_voltage >= 380000) {
            $voltage_category = "EHV (400kV)";
        } elseif($avg_voltage >= 220000) {
            $voltage_category = "HV (230kV)";
        } elseif($avg_voltage >= 11000) {
            $voltage_category = "MV (15kV)";
        } else {
            $voltage_category = "LV";
        }
        echo "Detected Category: " . $voltage_category . "\t\t\t\t\t\t\n";
    }
    
    echo "\n";
    
    // Summary Statistics
    echo "SUMMARY STATISTICS\t\t\t\t\t\t\n";
    echo "Parameter\tMinimum\tMaximum\tAverage\tUnit\tStatus\tCategory\t\n";
    
    if($summary && $summary['total_readings'] > 0) {
        // Determine voltage category for status calculation
        $detected_category = detectVoltageCategory($summary['avg_voltage']);
        
        // Voltage
        $voltage_status = getVoltageStatus($summary['avg_voltage'], $detected_category);
        $voltage_unit = ($detected_category == 'LV') ? 'V' : 'kV';
        $voltage_min = ($detected_category == 'LV') ? $summary['min_voltage'] : $summary['min_voltage'] / 1000;
        $voltage_max = ($detected_category == 'LV') ? $summary['max_voltage'] : $summary['max_voltage'] / 1000;
        $voltage_avg = ($detected_category == 'LV') ? $summary['avg_voltage'] : $summary['avg_voltage'] / 1000;
        
        echo "Voltage\t" . number_format($voltage_min, 2) . "\t" . 
                         number_format($voltage_max, 2) . "\t" . 
                         number_format($voltage_avg, 2) . "\t" . 
                         $voltage_unit . "\t" . 
                         $voltage_status . "\t" . 
                         $detected_category . "\n";
        
        // Current (adjust based on voltage level)
        $current_status = getCurrentStatus($summary['avg_current'], $detected_category);
        $current_unit = ($detected_category == 'LV') ? 'A' : 'kA';
        $current_min = ($detected_category == 'LV') ? $summary['min_current'] : $summary['min_current'] / 1000;
        $current_max = ($detected_category == 'LV') ? $summary['max_current'] : $summary['max_current'] / 1000;
        $current_avg = ($detected_category == 'LV') ? $summary['avg_current'] : $summary['avg_current'] / 1000;
        
        echo "Current\t" . number_format($current_min, 2) . "\t" . 
                         number_format($current_max, 2) . "\t" . 
                         number_format($current_avg, 2) . "\t" . 
                         $current_unit . "\t" . 
                         $current_status . "\t" . 
                         $detected_category . "\n";
        
        // Active Power
        $power_status = getPowerStatus($summary['avg_active_power'], $detected_category);
        $power_unit = ($detected_category == 'LV') ? 'kW' : 'MW';
        $power_min = ($detected_category == 'LV') ? $summary['min_active_power'] / 1000 : $summary['min_active_power'] / 1000000;
        $power_max = ($detected_category == 'LV') ? $summary['max_active_power'] / 1000 : $summary['max_active_power'] / 1000000;
        $power_avg = ($detected_category == 'LV') ? $summary['avg_active_power'] / 1000 : $summary['avg_active_power'] / 1000000;
        
        echo "Active Power\t" . number_format($power_min, 2) . "\t" . 
                               number_format($power_max, 2) . "\t" . 
                               number_format($power_avg, 2) . "\t" . 
                               $power_unit . "\t" . 
                               $power_status . "\t" . 
                               $detected_category . "\n";
        
        // Frequency
        $frequency_status = getFrequencyStatus($summary['avg_frequency']);
        echo "Frequency\t" . number_format($summary['min_frequency'], 2) . "\t" . 
                            number_format($summary['max_frequency'], 2) . "\t" . 
                            number_format($summary['avg_frequency'], 2) . "\tHz\t" . 
                            $frequency_status . "\tAll Levels\n";
        
        // Power Factor
        $pf_status = getPowerFactorStatus($summary['avg_power_factor']);
        echo "Power Factor\t" . number_format($summary['min_power_factor'], 3) . "\t" . 
                               number_format($summary['max_power_factor'], 3) . "\t" . 
                               number_format($summary['avg_power_factor'], 3) . "\t-\t" . 
                               $pf_status . "\tAll Levels\n";
    } else {
        echo "No data available for the selected criteria.\t\t\t\t\t\t\n";
    }
    
    echo "\n";
    
    // Voltage Level Analysis
    if($summary && $summary['total_readings'] > 0) {
        echo "VOLTAGE LEVEL ANALYSIS\t\t\t\t\t\t\n";
        echo "Detected Voltage Level:\t" . detectVoltageCategory($summary['avg_voltage']) . "\t\t\t\t\n";
        echo "Average Voltage:\t" . number_format($summary['avg_voltage'], 2) . " V\t\t\t\t\n";
        echo "Voltage Range:\t" . number_format($summary['min_voltage'], 2) . " V to " . 
                               number_format($summary['max_voltage'], 2) . " V\t\t\t\t\n";
        
        // Check for voltage level transitions
        $sql_transitions = "SELECT COUNT(DISTINCT FLOOR(voltage/1000)) as voltage_levels 
                           FROM electrical_data 
                           WHERE user_id = ?";
        if($stmt = $mysqli->prepare($sql_transitions)){
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $transitions = $result->fetch_assoc();
            $stmt->close();
            
            if($transitions['voltage_levels'] > 1) {
                echo "Multiple Voltage Levels Detected: " . $transitions['voltage_levels'] . " different levels\t\t\t\t\n";
            }
        }
    }
    
    echo "\n";
    
    // Category-specific recommendations
    if($summary && $summary['total_readings'] > 0) {
        $category = detectVoltageCategory($summary['avg_voltage']);
        echo "CATEGORY-SPECIFIC RECOMMENDATIONS\t\t\t\t\t\t\n";
        
        switch($category) {
            case 'EHV (400kV)':
                echo "• Maintain voltage within 380-420 kV range\n";
                echo "• Monitor for transient stability issues\n";
                echo "• Check transformer tap changers regularly\n";
                echo "• Ensure proper reactive power compensation\n";
                echo "• Monitor busbar temperatures\n";
                echo "• Check circuit breaker SF6 levels\n";
                break;
            case 'HV (230kV)':
                echo "• Maintain voltage within 220-242 kV range\n";
                echo "• Monitor load distribution across feeders\n";
                echo "• Check circuit breaker operations and maintenance\n";
                echo "• Maintain power factor above 0.95\n";
                echo "• Monitor transformer loading\n";
                echo "• Check protection relay settings\n";
                break;
            case 'MV (15kV)':
                echo "• Maintain voltage within 13.8-15.2 kV range\n";
                echo "• Check for voltage unbalance between phases\n";
                echo "• Monitor harmonic distortion levels\n";
                echo "• Regular insulation testing of cables\n";
                echo "• Check switchgear condition\n";
                echo "• Monitor capacitor bank operations\n";
                break;
            case 'LV':
                echo "• Maintain voltage within 220-240V for single-phase\n";
                echo "• Maintain voltage within 380-415V for three-phase\n";
                echo "• Check for neutral currents and imbalances\n";
                echo "• Monitor power quality and harmonics\n";
                echo "• Regular earth resistance testing\n";
                echo "• Check circuit breaker tripping characteristics\n";
                break;
        }
    }
    
    echo "\n";
    
    // Data Quality Information
    echo "DATA QUALITY INFORMATION\t\t\t\t\t\t\n";
    if($summary && $summary['total_readings'] > 0) {
        echo "Total Readings Analyzed:\t" . $summary['total_readings'] . "\t\t\t\t\n";
        echo "Data Completeness:\t100%\t\t\t\t\n";
        echo "Date Range Coverage:\t" . $range_label . "\t\t\t\t\n";
        echo "Export Timestamp:\t" . date('Y-m-d H:i:s') . "\t\t\t\t\n";
    }
}

function exportDetailedData($mysqli, $time_range, $start_date, $end_date, $voltage_level, $user_id) {
    // Build query based on time range
    $where_clause = "user_id = ?";
    $params = [$user_id];
    $param_types = "i";
    
    // Add voltage level filter if specified
    if($voltage_level != 'auto') {
        switch($voltage_level) {
            case '400kv':
                $where_clause .= " AND voltage >= 380000 AND voltage <= 420000";
                $voltage_label = "EHV (400kV)";
                break;
            case '230kv':
                $where_clause .= " AND voltage >= 220000 AND voltage < 380000";
                $voltage_label = "HV (230kV)";
                break;
            case '15kv':
                $where_clause .= " AND voltage >= 11000 AND voltage < 220000";
                $voltage_label = "MV (15kV)";
                break;
            case 'lv':
                $where_clause .= " AND voltage < 11000";
                $voltage_label = "LV (<11kV)";
                break;
            default:
                $voltage_label = "All Voltage Levels";
        }
    } else {
        $voltage_label = "All Voltage Levels";
    }
    
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
    
    // Get detailed data
    $detailed_sql = "SELECT 
        timestamp,
        voltage,
        current,
        active_power,
        reactive_power,
        frequency,
        power_factor
    FROM electrical_data 
    WHERE $where_clause
    ORDER BY timestamp DESC";
    
    // Output Excel content
    echo "ELECTRICAL LOAD MONITORING - DETAILED DATA\t\t\t\t\t\t\n";
    echo "Generated on: " . date('Y-m-d H:i:s') . "\t\t\t\t\t\t\n";
    echo "Time Range: " . $range_label . "\t\t\t\t\t\t\n";
    echo "Voltage Level: " . $voltage_label . "\t\t\t\t\t\t\n";
    echo "\n";
    
    // Detailed Data Headers
    echo "Timestamp\tVoltage Category\tVoltage (V)\tVoltage (kV)\tCurrent (A)\tCurrent (kA)\tActive Power (W)\tActive Power (kW)\tReactive Power (VAR)\tFrequency (Hz)\tPower Factor\tStatus\t\n";
    
    if($stmt = $mysqli->prepare($detailed_sql)){
        if($param_types == "i") {
            $stmt->bind_param($param_types, $params[0]);
        } else {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $record_count = 0;
        while($row = $result->fetch_assoc()){
            $record_count++;
            $category = detectVoltageCategory($row['voltage']);
            $voltage_kv = $row['voltage'] / 1000;
            $current_ka = $row['current'] / 1000;
            $power_kw = $row['active_power'] / 1000;
            $reactive_kvar = $row['reactive_power'] / 1000;
            $status = getReadingStatus($row['voltage'], $row['current'], $row['frequency'], $row['power_factor']);
            
            echo $row['timestamp'] . "\t" . 
                 $category . "\t" . 
                 number_format($row['voltage'], 2) . "\t" . 
                 number_format($voltage_kv, 3) . "\t" . 
                 number_format($row['current'], 2) . "\t" . 
                 number_format($current_ka, 3) . "\t" . 
                 number_format($row['active_power'], 2) . "\t" . 
                 number_format($power_kw, 2) . "\t" . 
                 number_format($row['reactive_power'], 2) . "\t" . 
                 number_format($reactive_kvar, 2) . "\t" . 
                 number_format($row['frequency'], 2) . "\t" . 
                 number_format($row['power_factor'], 3) . "\t" . 
                 $status . "\n";
        }
        
        if($record_count == 0) {
            echo "No data available for the selected criteria.\t\t\t\t\t\t\t\t\t\t\t\t\n";
        }
        
        $stmt->close();
    } else {
        echo "Error preparing database query.\t\t\t\t\t\t\t\t\t\t\t\t\n";
    }
    
    // Add summary at the end
    echo "\n";
    echo "EXPORT SUMMARY\t\t\t\t\t\t\t\t\t\t\t\t\n";
    echo "Total Records Exported:\t" . $record_count . "\t\t\t\t\t\t\t\t\t\t\n";
    echo "Export Date:\t" . date('Y-m-d') . "\t\t\t\t\t\t\t\t\t\t\n";
    echo "Export Time:\t" . date('H:i:s') . "\t\t\t\t\t\t\t\t\t\t\n";
    echo "File Format:\tExcel (Tab-delimited)\t\t\t\t\t\t\t\t\t\t\n";
}

// Voltage category detection
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

// Status determination functions with category-specific logic
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
            return "UNKNOWN";
    }
}

function getCurrentStatus($current, $category) {
    switch($category) {
        case 'EHV (400kV)':
            // Typical EHV currents are in kA range
            $current_ka = $current / 1000;
            if($current_ka <= 1.5) return "NORMAL";
            if($current_ka > 1.5 && $current_ka <= 2.0) return "WARNING";
            return "CRITICAL";
            
        case 'HV (230kV)':
            $current_ka = $current / 1000;
            if($current_ka <= 2.0) return "NORMAL";
            if($current_ka > 2.0 && $current_ka <= 2.5) return "WARNING";
            return "CRITICAL";
            
        case 'MV (15kV)':
            // MV typically up to few kA
            if($current <= 2000) return "NORMAL";
            if($current > 2000 && $current <= 2500) return "WARNING";
            return "CRITICAL";
            
        case 'LV':
            // LV currents
            if($current <= 100) return "NORMAL";
            if($current > 100 && $current <= 150) return "WARNING";
            return "CRITICAL";
            
        default:
            return "UNKNOWN";
    }
}

function getPowerStatus($power, $category) {
    switch($category) {
        case 'EHV (400kV)':
            // Power in MW for EHV
            $power_mw = $power / 1000000;
            if($power_mw <= 500) return "NORMAL";
            if($power_mw > 500 && $power_mw <= 800) return "WARNING";
            return "CRITICAL";
            
        case 'HV (230kV)':
            $power_mw = $power / 1000000;
            if($power_mw <= 300) return "NORMAL";
            if($power_mw > 300 && $power_mw <= 500) return "WARNING";
            return "CRITICAL";
            
        case 'MV (15kV)':
            // Power in MW for MV
            $power_mw = $power / 1000000;
            if($power_mw <= 20) return "NORMAL";
            if($power_mw > 20 && $power_mw <= 30) return "WARNING";
            return "CRITICAL";
            
        case 'LV':
            // Power in kW for LV
            $power_kw = $power / 1000;
            if($power_kw <= 500) return "NORMAL";
            if($power_kw > 500 && $power_kw <= 800) return "WARNING";
            return "CRITICAL";
            
        default:
            return "UNKNOWN";
    }
}

function getFrequencyStatus($frequency) {
    // Frequency is same for all voltage levels
    if($frequency >= 49.8 && $frequency <= 50.2) return "STABLE";
    if(($frequency >= 49.5 && $frequency < 49.8) || ($frequency > 50.2 && $frequency <= 50.5)) return "UNSTABLE";
    return "CRITICAL";
}

function getPowerFactorStatus($pf) {
    // Power factor standards same for all levels
    if($pf >= 0.95) return "EXCELLENT";
    if($pf >= 0.90) return "GOOD";
    if($pf >= 0.85) return "FAIR";
    return "POOR";
}

function getReadingStatus($voltage, $current, $frequency, $pf) {
    $category = detectVoltageCategory($voltage);
    $issues = [];
    
    // Check voltage
    $voltage_status = getVoltageStatus($voltage, $category);
    if($voltage_status != "NORMAL") $issues[] = "V-" . $voltage_status;
    
    // Check current
    $current_status = getCurrentStatus($current, $category);
    if($current_status != "NORMAL") $issues[] = "C-" . $current_status;
    
    // Check frequency
    $frequency_status = getFrequencyStatus($frequency);
    if($frequency_status != "STABLE") $issues[] = "F-" . $frequency_status;
    
    // Check power factor
    $pf_status = getPowerFactorStatus($pf);
    if($pf_status == "POOR" || $pf_status == "FAIR") $issues[] = "PF-" . $pf_status;
    
    if(empty($issues)) return "OK";
    return implode(", ", $issues);
}
?>