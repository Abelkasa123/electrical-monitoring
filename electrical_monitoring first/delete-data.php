<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

if($_SERVER["REQUEST_METHOD"] == "POST"){
    if(isset($_POST['id'])){
        $report_id = (int)$_POST['id'];
        $user_id = $_SESSION["id"];
        
        // Verify ownership before deleting
        $check_sql = "SELECT id FROM reports WHERE id = ? AND user_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("ii", $report_id, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if($check_result->num_rows > 0){
            // Delete the report
            $delete_sql = "DELETE FROM reports WHERE id = ?";
            $delete_stmt = $mysqli->prepare($delete_sql);
            $delete_stmt->bind_param("i", $report_id);
            
            if($delete_stmt->execute()){
                // Log the deletion
                $log_sql = "INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'delete_report', ?)";
                $log_stmt = $mysqli->prepare($log_sql);
                $details = "Deleted report #" . $report_id;
                $log_stmt->bind_param("is", $user_id, $details);
                $log_stmt->execute();
                $log_stmt->close();
                
                echo json_encode(['success' => true, 'message' => 'Report deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deleting report: ' . $delete_stmt->error]);
            }
            $delete_stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Report not found or unauthorized']);
        }
        $check_stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'No report ID provided']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

$mysqli->close();
?>