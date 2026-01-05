<?php
require_once "config/database.php";

/**
 * Calculate power factor from voltage, current, and active power
 * @param float $voltage
 * @param float $current
 * @param float $active_power
 * @return float
 */
function calculate_power_factor($voltage, $current, $active_power) {
    if ($voltage <= 0 || $current <= 0) {
        return 0;
    }
    
    $apparent_power = $voltage * $current;
    
    if ($apparent_power <= 0) {
        return 0;
    }
    
    $power_factor = $active_power / $apparent_power;
    
    // Ensure power factor is between 0 and 1
    return max(0, min(1, $power_factor));
}

/**
 * Calculate reactive power using Pythagorean theorem
 * @param float $active_power
 * @param float $apparent_power
 * @return float
 */
function calculate_reactive_power($active_power, $apparent_power) {
    if ($apparent_power <= $active_power) {
        return 0;
    }
    
    return sqrt(pow($apparent_power, 2) - pow($active_power, 2));
}

/**
 * Validate electrical parameter values
 * @param array $data
 * @return array Array of validation errors
 */
function validate_electrical_data($data) {
    $errors = [];
    
    // Validate voltage (typical range: 100-500V)
    if (!isset($data['voltage']) || !is_numeric($data['voltage'])) {
        $errors['voltage'] = "Voltage must be a number";
    } elseif ($data['voltage'] < 100 || $data['voltage'] > 500) {
        $errors['voltage'] = "Voltage must be between 100V and 500V";
    }
    
    // Validate current (typical range: 0-100A)
    if (!isset($data['current']) || !is_numeric($data['current'])) {
        $errors['current'] = "Current must be a number";
    } elseif ($data['current'] < 0 || $data['current'] > 100) {
        $errors['current'] = "Current must be between 0A and 100A";
    }
    
    // Validate active power
    if (!isset($data['active_power']) || !is_numeric($data['active_power'])) {
        $errors['active_power'] = "Active power must be a number";
    } elseif ($data['active_power'] < 0) {
        $errors['active_power'] = "Active power cannot be negative";
    }
    
    // Validate frequency (typical range: 47-53Hz)
    if (!isset($data['frequency']) || !is_numeric($data['frequency'])) {
        $errors['frequency'] = "Frequency must be a number";
    } elseif ($data['frequency'] < 47 || $data['frequency'] > 53) {
        $errors['frequency'] = "Frequency must be between 47Hz and 53Hz";
    }
    
    return $errors;
}

/**
 * Get daily summary for a specific user
 * @param int $user_id
 * @param string $date Date in Y-m-d format
 * @return array
 */
function get_daily_summary($user_id, $date = null) {
    global $mysqli;
    
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    $sql = "SELECT 
        DATE(timestamp) as date,
        COUNT(*) as readings_count,
        MIN(voltage) as min_voltage,
        MAX(voltage) as max_voltage,
        AVG(voltage) as avg_voltage,
        MIN(current) as min_current,
        MAX(current) as max_current,
        AVG(current) as avg_current,
        MIN(active_power) as min_active_power,
        MAX(active_power) as max_active_power,
        AVG(active_power) as avg_active_power,
        SUM(active_power * TIMESTAMPDIFF(SECOND, LAG(timestamp) OVER (ORDER BY timestamp), timestamp) / 3600) as total_energy_kwh,
        MIN(reactive_power) as min_reactive_power,
        MAX(reactive_power) as max_reactive_power,
        AVG(reactive_power) as avg_reactive_power,
        MIN(frequency) as min_frequency,
        MAX(frequency) as max_frequency,
        AVG(frequency) as avg_frequency,
        AVG(power_factor) as avg_power_factor
    FROM electrical_data 
    WHERE user_id = ? 
    AND DATE(timestamp) = ?
    GROUP BY DATE(timestamp)";
    
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("is", $user_id, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Get 24-hour rolling summary
 * @param int $user_id
 * @return array
 */
function get_24h_summary($user_id) {
    global $mysqli;
    
    $sql = "SELECT 
        MIN(voltage) as min_voltage,
        MAX(voltage) as max_voltage,
        AVG(voltage) as avg_voltage,
        MIN(current) as min_current,
        MAX(current) as max_current,
        AVG(current) as avg_current,
        MIN(active_power) as min_active_power,
        MAX(active_power) as max_active_power,
        AVG(active_power) as avg_active_power,
        MIN(reactive_power) as min_reactive_power,
        MAX(reactive_power) as max_reactive_power,
        AVG(reactive_power) as avg_reactive_power,
        MIN(frequency) as min_frequency,
        MAX(frequency) as max_frequency,
        AVG(frequency) as avg_frequency,
        AVG(power_factor) as avg_power_factor
    FROM electrical_data 
    WHERE user_id = ? 
    AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Format electrical value with units
 * @param float $value
 * @param string $type
 * @return string
 */
function format_electrical_value($value, $type) {
    $units = [
        'voltage' => ' V',
        'current' => ' A',
        'active_power' => ' W',
        'reactive_power' => ' VAR',
        'frequency' => ' Hz',
        'power_factor' => ''
    ];
    
    $unit = isset($units[$type]) ? $units[$type] : '';
    
    if ($value === null) {
        return 'N/A';
    }
    
    // Format based on type
    switch ($type) {
        case 'power_factor':
            return number_format($value, 3) . $unit;
        case 'frequency':
            return number_format($value, 2) . $unit;
        default:
            return number_format($value, 2) . $unit;
    }
}

/**
 * Import data from CSV/Excel file
 * @param string $file_path
 * @param int $user_id
 * @return array Result with success count and errors
 */
function import_electrical_data($file_path, $user_id) {
    global $mysqli;
    
    $result = [
        'success' => 0,
        'errors' => [],
        'total' => 0
    ];
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        $headers = fgetcsv($handle);
        $result['total'] = -1; // Subtract header row
        
        // Prepare SQL statement
        $sql = "INSERT INTO electrical_data (user_id, timestamp, voltage, current, active_power, reactive_power, frequency, power_factor) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $mysqli->prepare($sql);
        
        if (!$stmt) {
            $result['errors'][] = "Database preparation failed: " . $mysqli->error;
            fclose($handle);
            return $result;
        }
        
        $stmt->bind_param("isdddddd", $user_id, $timestamp, $voltage, $current, $active_power, $reactive_power, $frequency, $power_factor);
        
        while (($data = fgetcsv($handle)) !== FALSE) {
            $result['total']++;
            
            // Map CSV columns (adjust based on your format)
            $timestamp = $data[0] ?? date('Y-m-d H:i:s');
            $voltage = floatval($data[1] ?? 0);
            $current = floatval($data[2] ?? 0);
            $active_power = floatval($data[3] ?? 0);
            $reactive_power = floatval($data[4] ?? 0);
            $frequency = floatval($data[5] ?? 50);
            $power_factor = floatval($data[6] ?? 0);
            
            // Auto-calculate missing values
            if ($power_factor == 0 && $voltage > 0 && $current > 0) {
                $power_factor = calculate_power_factor($voltage, $current, $active_power);
            }
            
            if ($reactive_power == 0 && $active_power > 0) {
                $apparent_power = $voltage * $current;
                $reactive_power = calculate_reactive_power($active_power, $apparent_power);
            }
            
            // Validate data
            $validation = validate_electrical_data([
                'voltage' => $voltage,
                'current' => $current,
                'active_power' => $active_power,
                'frequency' => $frequency
            ]);
            
            if (!empty($validation)) {
                $result['errors'][] = "Row " . ($result['total'] + 1) . ": " . implode(", ", $validation);
                continue;
            }
            
            // Insert data
            if ($stmt->execute()) {
                $result['success']++;
            } else {
                $result['errors'][] = "Row " . ($result['total'] + 1) . ": Insert failed";
            }
        }
        
        $stmt->close();
        fclose($handle);
    }
    
    return $result;
}

/**
 * Export data to Excel format
 * @param int $user_id
 * @param string $start_date
 * @param string $end_date
 * @return void (sends file to browser)
 */
function export_electrical_data($user_id, $start_date = null, $end_date = null) {
    global $mysqli;
    
    $sql = "SELECT timestamp, voltage, current, active_power, reactive_power, frequency, power_factor 
            FROM electrical_data 
            WHERE user_id = ?";
    
    $params = [$user_id];
    $types = "i";
    
    if ($start_date) {
        $sql .= " AND timestamp >= ?";
        $params[] = $start_date;
        $types .= "s";
    }
    
    if ($end_date) {
        $sql .= " AND timestamp <= ?";
        $params[] = $end_date;
        $types .= "s";
    }
    
    $sql .= " ORDER BY timestamp DESC";
    
    $stmt = $mysqli->prepare($sql);
    
    if ($stmt) {
        if (count($params) > 1) {
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param($types, $user_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="electrical_data_' . date('Y-m-d') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output Excel headers
        echo "Timestamp\tVoltage (V)\tCurrent (A)\tActive Power (W)\tReactive Power (VAR)\tFrequency (Hz)\tPower Factor\n";
        
        while ($row = $result->fetch_assoc()) {
            echo $row['timestamp'] . "\t";
            echo $row['voltage'] . "\t";
            echo $row['current'] . "\t";
            echo $row['active_power'] . "\t";
            echo $row['reactive_power'] . "\t";
            echo $row['frequency'] . "\t";
            echo $row['power_factor'] . "\n";
        }
        
        $stmt->close();
        exit;
    }
}

/**
 * Sanitize user input
 * @param string $input
 * @return string
 */
function sanitize_input($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

/**
 * Generate energy consumption chart data
 * @param int $user_id
 * @param int $days
 * @return array
 */
function get_energy_chart_data($user_id, $days = 7) {
    global $mysqli;
    
    $sql = "SELECT 
        DATE(timestamp) as date,
        AVG(active_power) as avg_power,
        SUM(active_power * TIMESTAMPDIFF(SECOND, LAG(timestamp) OVER (ORDER BY timestamp), timestamp) / 3600) as daily_energy
    FROM electrical_data 
    WHERE user_id = ? 
    AND timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY DATE(timestamp)
    ORDER BY date";
    
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("ii", $user_id, $days);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [
            'labels' => [],
            'energy' => [],
            'power' => []
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['date'];
            $data['energy'][] = round($row['daily_energy'] ?? 0, 2);
            $data['power'][] = round($row['avg_power'] ?? 0, 2);
        }
        
        return $data;
    }
    
    return ['labels' => [], 'energy' => [], 'power' => []];
}

/**
 * Check if user exists
 * @param string $username
 * @return bool
 */
function user_exists($username) {
    global $mysqli;
    
    $sql = "SELECT id FROM users WHERE username = ?";
    
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        
        return $exists;
    }
    
    return false;
}
?>