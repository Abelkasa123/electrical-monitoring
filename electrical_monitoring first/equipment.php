<?php
require_once "includes/auth.php";
requireLogin();
require_once "config/database.php";

// Handle equipment operations
if($_SERVER["REQUEST_METHOD"] == "POST") {
    if(isset($_POST['add_equipment'])) {
        // Add new equipment
        $sql = "INSERT INTO equipment (equipment_tag, equipment_name, equipment_type, 
                nominal_voltage, max_voltage, rated_current, manufacturer, 
                installation_date, substation, user_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssddssssi", 
            $_POST['equipment_tag'],
            $_POST['equipment_name'],
            $_POST['equipment_type'],
            $_POST['nominal_voltage'],
            $_POST['max_voltage'],
            $_POST['rated_current'],
            $_POST['manufacturer'],
            $_POST['installation_date'],
            $_POST['substation'],
            $_SESSION['id']
        );
        
        if($stmt->execute()) {
            $success_msg = "Equipment added successfully!";
        }
        $stmt->close();
    }
}

// Get user's equipment
$eq_stmt = $mysqli->prepare("SELECT * FROM equipment WHERE user_id = ? ORDER BY equipment_type, nominal_voltage DESC");
$eq_stmt->bind_param("i", $_SESSION['id']);
$eq_stmt->execute();
$equipment = $eq_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$eq_stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Equipment Management</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <?php include 'includes/navbar.php'; ?>
        
        <h2><i class="fas fa-cogs"></i> Equipment Management</h2>
        
        <!-- Add Equipment Form -->
        <div class="form-section">
            <h3><i class="fas fa-plus-circle"></i> Add New Equipment</h3>
            <form method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label>Equipment Tag *</label>
                        <input type="text" name="equipment_tag" class="form-control" 
                               placeholder="e.g., TRF-001" required>
                    </div>
                    <div class="form-group">
                        <label>Equipment Name *</label>
                        <input type="text" name="equipment_name" class="form-control" 
                               placeholder="e.g., Main Power Transformer" required>
                    </div>
                    <div class="form-group">
                        <label>Equipment Type *</label>
                        <select name="equipment_type" class="form-control" required>
                            <option value="">-- Select Type --</option>
                            <option value="transformer">Transformer</option>
                            <option value="switchgear">Switchgear</option>
                            <option value="circuit_breaker">Circuit Breaker</option>
                            <option value="cable">Cable</option>
                            <option value="busbar">Busbar</option>
                            <option value="capacitor_bank">Capacitor Bank</option>
                            <option value="reactor">Reactor</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group input-with-unit">
                        <label>Nominal Voltage (V) *</label>
                        <input type="number" step="0.001" name="nominal_voltage" 
                               class="form-control" required>
                        <span class="unit">V</span>
                    </div>
                    <div class="form-group input-with-unit">
                        <label>Maximum Voltage (V)</label>
                        <input type="number" step="0.001" name="max_voltage" 
                               class="form-control">
                        <span class="unit">V</span>
                    </div>
                    <div class="form-group input-with-unit">
                        <label>Rated Current (A)</label>
                        <input type="number" step="0.001" name="rated_current" 
                               class="form-control">
                        <span class="unit">A</span>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Manufacturer</label>
                        <input type="text" name="manufacturer" class="form-control" 
                               placeholder="e.g., Siemens, ABB">
                    </div>
                    <div class="form-group">
                        <label>Installation Date</label>
                        <input type="date" name="installation_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Substation</label>
                        <input type="text" name="substation" class="form-control" 
                               placeholder="e.g., Main Substation">
                    </div>
                </div>
                
                <button type="submit" name="add_equipment" class="btn btn-submit">
                    <i class="fas fa-save"></i> Add Equipment
                </button>
            </form>
        </div>
        
        <!-- Equipment List -->
        <div class="form-section">
            <h3><i class="fas fa-list"></i> Your Equipment</h3>
            <?php if(empty($equipment)): ?>
                <p class="no-data">No equipment registered yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Voltage</th>
                            <th>Current</th>
                            <th>Substation</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($equipment as $eq): ?>
                            <tr>
                                <td><strong><?php echo $eq['equipment_tag']; ?></strong></td>
                                <td><?php echo $eq['equipment_name']; ?></td>
                                <td>
                                    <?php 
                                    $type_names = [
                                        'transformer' => 'Transformer',
                                        'switchgear' => 'Switchgear',
                                        'circuit_breaker' => 'Circuit Breaker',
                                        'cable' => 'Cable',
                                        'busbar' => 'Busbar',
                                        'capacitor_bank' => 'Capacitor Bank',
                                        'reactor' => 'Reactor'
                                    ];
                                    echo $type_names[$eq['equipment_type']] ?? $eq['equipment_type'];
                                    ?>
                                </td>
                                <td><?php echo number_format($eq['nominal_voltage']/1000, 2); ?> kV</td>
                                <td><?php echo $eq['rated_current'] ? number_format($eq['rated_current'], 2) . ' A' : 'N/A'; ?></td>
                                <td><?php echo $eq['substation'] ?: 'N/A'; ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $eq['status']; ?>">
                                        <?php echo ucfirst($eq['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn-action btn-edit" onclick="editEquipment(<?php echo $eq['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    function editEquipment(id) {
        window.location.href = "edit-equipment.php?id=" + id;
    }
    </script>
</body>
</html>
<?php
if(isset($mysqli)){
    $mysqli->close();
}
?>