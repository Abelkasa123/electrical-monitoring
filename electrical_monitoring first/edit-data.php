<?php
session_start();
require_once "config/database.php";
require_once "data-entry.php";
header('Content-Type: application/json');

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION["id"];
$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if(!$data || !is_array($data)) {
        throw new Exception('Invalid data received');
    }
    
    $saved_count = 0;
    
    foreach($data as $record) {
        if(isset($record['new']) && $record['new'] === true) {
            // Insert new record
            $sql = "INSERT INTO electrical_data 
                    (user_id, voltage, current, active_power, reactive_power, 
                     frequency, power_factor, voltage_category_id, timestamp) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("iddddddi",
                $user_id,
                $record['voltage'] ?? 0,
                $record['current'] ?? 0,
                $record['active_power'] ?? 0,
                $record['reactive_power'] ?? 0,
                $record['frequency'] ?? 0,
                $record['power_factor'] ?? 1,
                $record['voltage_category_id'] ?? null
            );
            
            if($stmt->execute()) {
                $saved_count++;
            }
            $stmt->close();
            
        } else if(isset($record['id'])) {
            // Update existing record
            $updates = [];
            $params = [];
            $types = "";
            
            // Build dynamic update query
            $allowed_fields = ['voltage', 'current', 'active_power', 'reactive_power', 
                              'frequency', 'power_factor', 'voltage_category_id'];
            
            foreach($allowed_fields as $field) {
                if(isset($record[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $record[$field];
                    $types .= is_float($record[$field]) ? "d" : (is_int($record[$field]) ? "i" : "s");
                }
            }
            
            if(!empty($updates)) {
                $params[] = $record['id'];
                $params[] = $user_id;
                $types .= "ii";
                
                $sql = "UPDATE electrical_data SET " . implode(", ", $updates) . 
                       " WHERE id = ? AND user_id = ?";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param($types, ...$params);
                
                if($stmt->execute()) {
                    $saved_count++;
                }
                $stmt->close();
            }
        }
    }
    
    $response['success'] = true;
    $response['message'] = "Saved $saved_count record(s)";
    $response['saved_count'] = $saved_count;
    
} catch(Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
$mysqli->close();
?>