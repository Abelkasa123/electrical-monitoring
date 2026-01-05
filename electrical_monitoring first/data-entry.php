<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];
$is_edit_mode = false;
$record_id = null;
$record_data = null;

// Check if we're in edit mode
if(isset($_GET['edit']) && !empty($_GET['edit'])) {
    $is_edit_mode = true;
    $record_id = $_GET['edit'];
    
    // Fetch the record data
    $sql = "SELECT e.*, vc.category_name 
            FROM electrical_data e 
            LEFT JOIN voltage_categories vc ON e.voltage_category_id = vc.id 
            WHERE e.id = ? AND e.user_id = ?";
    
    if($stmt = $mysqli->prepare($sql)){
        $stmt->bind_param("ii", $record_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if($result->num_rows == 1){
            $record_data = $result->fetch_assoc();
        } else {
            header("location: view-data.php");
            exit;
        }
        $stmt->close();
    }
}

// Get voltage categories
$voltage_categories = [];
$cat_sql = "SELECT id, category_name FROM voltage_categories ORDER BY id";
$cat_result = $mysqli->query($cat_sql);
while($cat_row = $cat_result->fetch_assoc()){
    $voltage_categories[] = $cat_row;
}

// Handle form submission
if($_SERVER["REQUEST_METHOD"] == "POST"){
    $voltage = $_POST['voltage'] ?? '';
    $current = $_POST['current'] ?? '';
    $active_power = $_POST['active_power'] ?? '';
    $reactive_power = $_POST['reactive_power'] ?? '';
    $frequency = $_POST['frequency'] ?? '';
    $power_factor = $_POST['power_factor'] ?? '';
    $voltage_category_id = $_POST['voltage_category_id'] ?? '';
    $timestamp = $_POST['timestamp'] ?? date('Y-m-d H:i:s');
    
    // Validate input
    $errors = [];
    
    if(empty($voltage)) $errors[] = "Voltage is required";
    if(empty($current)) $errors[] = "Current is required";
    if(empty($active_power)) $errors[] = "Active power is required";
    if(empty($frequency)) $errors[] = "Frequency is required";
    
    if(empty($errors)){
        if($is_edit_mode && isset($_POST['record_id'])){
            // Update existing record
            $sql = "UPDATE electrical_data SET 
                    voltage = ?, current = ?, active_power = ?, 
                    reactive_power = ?, frequency = ?, power_factor = ?,
                    voltage_category_id = ?, timestamp = ?
                    WHERE id = ? AND user_id = ?";
            
            if($stmt = $mysqli->prepare($sql)){
                $stmt->bind_param("ddddddissi", 
                    $voltage, $current, $active_power,
                    $reactive_power, $frequency, $power_factor,
                    $voltage_category_id, $timestamp,
                    $record_id, $user_id
                );
                
                if($stmt->execute()){
                    $_SESSION['success_message'] = "Record updated successfully!";
                    header("location: view-data.php");
                    exit;
                } else {
                    $errors[] = "Error updating record: " . $mysqli->error;
                }
                $stmt->close();
            }
        } else {
            // Insert new record
            $sql = "INSERT INTO electrical_data 
                    (user_id, voltage, current, active_power, reactive_power, 
                     frequency, power_factor, voltage_category_id, timestamp) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            if($stmt = $mysqli->prepare($sql)){
                $stmt->bind_param("iddddddss", 
                    $user_id, $voltage, $current, $active_power,
                    $reactive_power, $frequency, $power_factor,
                    $voltage_category_id, $timestamp
                );
                
                if($stmt->execute()){
                    $_SESSION['success_message'] = "Record added successfully!";
                    header("location: view-data.php");
                    exit;
                } else {
                    $errors[] = "Error adding record: " . $mysqli->error;
                }
                $stmt->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit_mode ? 'Edit' : 'Add'; ?> Electrical Data</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(232, 32, 32, 0.1);
            padding: 30px;
            margin-top: 20px;
        }
        
        .parameter-box {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            background: #f8f9fa;
        }
        
        .parameter-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #0d6efd;
        }
        
        .unit {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .calculated-value {
            background: #e7f6ea;
            padding: 10px;
            border-radius: 5px;
            border-left: 4px solid #107c41;
        }
        
        .live-preview {
            background: #fff3cd;
            border: 1px dashed #ffc107;
            padding: 15px;
            border-radius: 5px;
        }
        
        .form-section {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        
        .btn-excel {
            background: #107c41;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
        }
        
        .btn-excel:hover {
            background: #0d6b37;
            color: white;
        }
        
        .input-group-text {
            background: #e7f6ea;
            border: 1px solid #107c41;
            color: #107c41;
            font-weight: 500;
        }
        
        .form-control:focus {
            border-color: #107c41;
            box-shadow: 0 0 0 0.2rem rgba(16, 124, 65, 0.25);
        }
        
        .real-time-calc {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: #107c41;">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-bolt"></i> Electrical Data <?php echo $is_edit_mode ? 'Editor' : 'Entry'; ?>
            </a>
            <div class="d-flex">
                <a href="view-data.php" class="btn btn-light btn-sm me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Excel View
                </a>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>
    
    <div class="container mt-4">
        <!-- Success/Error Messages -->
        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success_message']; ?>
                <?php unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="form-card">
            <form method="POST" action="" id="dataEntryForm">
                <?php if($is_edit_mode): ?>
                    <input type="hidden" name="record_id" value="<?php echo $record_id; ?>">
                <?php endif; ?>
                
                <!-- Record Info -->
                <div class="form-section">
                    <h4>
                        <i class="fas fa-<?php echo $is_edit_mode ? 'edit' : 'plus-circle'; ?> me-2"></i>
                        <?php echo $is_edit_mode ? 'Edit Record #' . $record_id : 'Add New Electrical Data'; ?>
                    </h4>
                    <p class="text-muted">
                        <?php echo $is_edit_mode ? 'Update the electrical parameters below' : 'Enter electrical parameters for the new record'; ?>
                    </p>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Timestamp</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                <input type="datetime-local" class="form-control" name="timestamp" 
                                       value="<?php echo $is_edit_mode ? date('Y-m-d\TH:i', strtotime($record_data['timestamp'])) : date('Y-m-d\TH:i'); ?>"
                                       required>
                            </div>
                            <small class="form-text text-muted">Date and time of measurement</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Voltage Category</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-bolt"></i></span>
                                <select class="form-select" name="voltage_category_id">
                                    <option value="">Select Category</option>
                                    <?php foreach($voltage_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"
                                            <?php echo ($is_edit_mode && $record_data['voltage_category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Electrical Parameters -->
                <div class="form-section">
                    <h5><i class="fas fa-sliders-h me-2"></i> Electrical Parameters</h5>
                    <p class="text-muted">Enter measured values</p>
                    
                    <div class="row">
                        <!-- Voltage -->
                        <div class="col-md-6 mb-3">
                            <div class="parameter-box">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-bolt text-warning"></i> Voltage (kV)
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="voltage" id="voltage" 
                                           step="0.01" min="0" max="?" required
                                           value="<?php echo $is_edit_mode ? $record_data['voltage'] : ''; ?>"
                                           oninput="calculatePower()">
                                    <span class="input-group-text">KV</span>
                                </div>
                                <div class="real-time-calc">
                                    <span id="voltageStatus"></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Current -->
                        <div class="col-md-6 mb-3">
                            <div class="parameter-box">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-tachometer-alt text-primary"></i> Current (A)
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="current" id="current" 
                                           step="0.01" min="0" max="" required
                                           value="<?php echo $is_edit_mode ? $record_data['current'] : ''; ?>"
                                           oninput="calculatePower()">
                                    <span class="input-group-text">A</span>
                                </div>
                                <div class="real-time-calc">
                                    <span id="currentStatus"></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Active Power (Calculated) -->
                        <div class="col-md-6 mb-3">
                            <div class="parameter-box">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-power-off text-success"></i> Active Power (KW)
                                </label>
                                <div class="calculated-value">
                                    <div class="parameter-value">
                                        <span id="activePowerDisplay">
                                            <?php echo $is_edit_mode ? number_format($record_data['active_power'], 2) : ''; ?>
                                        </span>
                                        <span class="unit">KW</span>
                                    </div>
                                    <input type="hidden" name="active_power" id="activePower" 
                                           value="<?php echo $is_edit_mode ? $record_data['active_power'] : ''; ?>">
                                    <small class="text-muted">Calculated:1.732 × V × I × PF</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Frequency -->
                        <div class="col-md-6 mb-3">
                            <div class="parameter-box">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-wave-square text-info"></i> Frequency (Hz)
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="frequency" id="frequency"
                                           step="0.01" min="0" max="60" required
                                           value="<?php echo $is_edit_mode ? $record_data['frequency'] : ''; ?>">
                                    <span class="input-group-text">Hz</span>
                                </div>
                                <div class="real-time-calc">
                                    <span id="frequencyStatus"></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Power Factor -->
                        <div class="col-md-6 mb-3">
                            <div class="parameter-box">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-percentage text-danger"></i> Power Factor
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="power_factor" id="powerFactor"
                                           step="0.001" min="0" max="1" required
                                           value="<?php echo $is_edit_mode ? $record_data['power_factor'] : '1'; ?>"
                                           oninput="calculatePower()">
                                    <span class="input-group-text">0-1</span>
                                </div>
                                <div class="real-time-calc">
                                    Power Factor: <span id="pfStatus">Good (1)</span>
                                    <div class="progress mt-1" style="height: 5px;">
                                        <div id="pfProgress" class="progress-bar bg-success" style="width: 100%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Reactive Power -->
                        <div class="col-md-6 mb-3">
                            <div class="parameter-box">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-exchange-alt text-warning"></i> Reactive Power (KVAR)
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="reactive_power" id="reactivePower"
                                           step="0.01"
                                           value="<?php echo $is_edit_mode ? $record_data['reactive_power'] : ''; ?>">
                                    <span class="input-group-text">KVAR</span>
                                </div>
                                <small class="text-muted">Optional:1.72 × V × I × sin(φ)</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Live Preview -->
                <div class="live-preview mb-4">
                    <h6><i class="fas fa-eye me-2"></i> Live Preview</h6>
                    <div class="row">
                        <div class="col-md-3">
                            <small>Voltage:</small>
                            <div class="fw-bold" id="previewVoltage">230.00 kV</div>
                        </div>
                        <div class="col-md-3">
                            <small>Current:</small>
                            <div class="fw-bold" id="previewCurrent">10.00 A</div>
                        </div>
                        <div class="col-md-3">
                            <small>Power:</small>
                            <div class="fw-bold" id="previewPower">2300.00 kW</div>
                        </div>
                        <div class="col-md-3">
                            <small>Power Factor:</small>
                            <div class="fw-bold" id="previewPF">1.000</div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="d-flex justify-content-between">
                    <div>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">
                            <i class="fas fa-times me-1"></i> Cancel
                        </button>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="fillSampleData()">
                            <i class="fas fa-vial me-1"></i> Fill Sample
                        </button>
                        <button type="button" class="btn btn-outline-info" onclick="calculateFromPower()">
                            <i class="fas fa-calculator me-1"></i> Calculate
                        </button>
                        <button type="submit" class="btn btn-excel">
                            <i class="fas fa-<?php echo $is_edit_mode ? 'save' : 'plus'; ?> me-1"></i>
                            <?php echo $is_edit_mode ? 'Update Record' : 'Add Record'; ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Quick Actions Footer -->
    <div class="container mt-4 mb-4">
        <div class="card">
            <div class="card-body py-2">
                <div class="d-flex justify-content-center gap-3">
                    <small>
                        <a href="view-data.php" class="text-decoration-none">
                            <i class="fas fa-table me-1"></i> Excel View
                        </a>
                    </small>
                    <small>
                        <a href="import-export.php" class="text-decoration-none">
                            <i class="fas fa-file-import me-1"></i> Import/Export
                        </a>
                    </small>
                    <small>
                        <a href="dashboard.php" class="text-decoration-none">
                            <i class="fas fa-chart-bar me-1"></i> Dashboard
                        </a>
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize with current values
        function updatePreview() {
            document.getElementById('previewVoltage').textContent = 
                document.getElementById('voltage').value + ' V';
            document.getElementById('previewCurrent').textContent = 
                document.getElementById('current').value + ' A';
            document.getElementById('previewPower').textContent = 
                document.getElementById('activePowerDisplay').textContent + ' W';
            document.getElementById('previewPF').textContent = 
                document.getElementById('powerFactor').value;
            
            // Update PF status
            const pf = parseFloat(document.getElementById('powerFactor').value);
            const pfStatus = document.getElementById('pfStatus');
            const pfProgress = document.getElementById('pfProgress');
            
            if(pf >= 0.9) {
                pfStatus.textContent = 'Good (' + pf.toFixed(3) + ')';
                pfStatus.className = 'text-success';
                pfProgress.className = 'progress-bar bg-success';
                pfProgress.style.width = (pf * 100) + '%';
            } else if(pf >= 0.8) {
                pfStatus.textContent = 'Fair (' + pf.toFixed(3) + ')';
                pfStatus.className = 'text-warning';
                pfProgress.className = 'progress-bar bg-warning';
                pfProgress.style.width = (pf * 100) + '%';
            } else {
                pfStatus.textContent = 'Poor (' + pf.toFixed(3) + ')';
                pfStatus.className = 'text-danger';
                pfProgress.className = 'progress-bar bg-danger';
                pfProgress.style.width = (pf * 100) + '%';
            }
        }
        
        function calculatePower() {
            const voltage = parseFloat(document.getElementById('voltage').value) || 0;
            const current = parseFloat(document.getElementById('current').value) || 0;
            const powerFactor = parseFloat(document.getElementById('powerFactor').value) || 1;

            // Calculate active power (P = 1.72 × V × I × PF)
            const activePower = 1.72 * voltage * current * powerFactor;
            
            // Update display and hidden field
            document.getElementById('activePowerDisplay').textContent = activePower.toFixed(2);
            document.getElementById('activePower').value = activePower.toFixed(2);
            
            // Calculate reactive power if needed
            if(powerFactor < 1) {
                const apparentPower = voltage * current;
                const reactivePower = Math.sqrt(Math.pow(apparentPower, 2) - Math.pow(activePower, 2));
                document.getElementById('reactivePower').value = reactivePower.toFixed(2);
            } else {
                document.getElementById('reactivePower').value = '0.00';
            }
            
            updatePreview();
        }
        
        function calculateFromPower() {
            const activePower = parseFloat(prompt("Enter desired active power (kW):", 
                document.getElementById('activePowerDisplay').textContent)) || 0;
            const voltage = parseFloat(document.getElementById('voltage').value) || 230000;
            const powerFactor = parseFloat(document.getElementById('powerFactor').value) || 1;
            
            if(voltage > 0 && powerFactor > 0) {
                // Calculate required current: I = P / (V × PF)
                const requiredCurrent = 1.72 * activePower / (voltage * powerFactor);
                document.getElementById('current').value = requiredCurrent.toFixed(2);
                calculatePower();
            }
        }
        
        function fillSampleData() {
            const samples = [
                {voltage: 230000.00, current: 150.50, frequency: 50.00, pf: 0.95},
                {voltage: 415000.00, current: 25.00, frequency: 50.00, pf: 0.92},
                {voltage: 110000.00, current: 8.50, frequency: 60.00, pf: 0.98},
                {voltage: 230000.00, current: 32.00, frequency: 50.00, pf: 0.88}
            ];
            
            const sample = samples[Math.floor(Math.random() * samples.length)];
            
            document.getElementById('voltage').value = sample.voltage;
            document.getElementById('current').value = sample.current;
            document.getElementById('frequency').value = sample.frequency;
            document.getElementById('powerFactor').value = sample.pf;
            
            calculatePower();
            alert('Sample data loaded. Review and adjust as needed.');
        }
        
        // Real-time validation
        function validateInputs() {
            const voltage = document.getElementById('voltage').value;
            const current = document.getElementById('current').value;
            const frequency = document.getElementById('frequency').value;
            
            // Voltage validation (typical ranges)
            if(voltage) {
                const v = parseFloat(voltage);
                if(v < 50) document.getElementById('voltageStatus').innerHTML = '<span class="text-danger">Low Voltage</span>';
                else if(v > 500) document.getElementById('voltageStatus').innerHTML = '<span class="text-danger">High Voltage</span>';
                else document.getElementById('voltageStatus').innerHTML = '<span class="text-success">Normal</span>';
            }
            
            // Current validation
            if(current) {
                const c = parseFloat(current);
                if(c > 100) document.getElementById('currentStatus').innerHTML = '<span class="text-danger">High Current</span>';
                else document.getElementById('currentStatus').innerHTML = '<span class="text-success">Normal</span>';
            }
            
            // Frequency validation
            if(frequency) {
                const f = parseFloat(frequency);
                if(f < 49 || f > 51) document.getElementById('frequencyStatus').innerHTML = '<span class="text-danger">Out of range</span>';
                else document.getElementById('frequencyStatus').innerHTML = '<span class="text-success">Normal</span>';
            }
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updatePreview();
            validateInputs();
            
            // Add real-time validation
            document.getElementById('voltage').addEventListener('input', validateInputs);
            document.getElementById('current').addEventListener('input', validateInputs);
            document.getElementById('frequency').addEventListener('input', validateInputs);
            document.getElementById('powerFactor').addEventListener('input', validateInputs);
            
            // Auto-calculate on load for edit mode
            if(<?php echo $is_edit_mode ? 'true' : 'false'; ?>) {
                calculatePower();
            }
        });
        
        // Auto-save draft
        let draftTimer;
        document.querySelectorAll('input, select').forEach(element => {
            element.addEventListener('input', function() {
                clearTimeout(draftTimer);
                draftTimer = setTimeout(saveDraft, 2000);
            });
        });
        
        function saveDraft() {
            const formData = new FormData(document.getElementById('dataEntryForm'));
            const draft = {};
            formData.forEach((value, key) => draft[key] = value);
            
            localStorage.setItem('electricalDataDraft', JSON.stringify(draft));
            console.log('Draft saved locally');
        }
        
        function loadDraft() {
            const draft = JSON.parse(localStorage.getItem('electricalDataDraft'));
            if(draft) {
                if(confirm('Load saved draft?')) {
                    for(const key in draft) {
                        const element = document.querySelector(`[name="${key}"]`);
                        if(element) element.value = draft[key];
                    }
                    calculatePower();
                }
            }
        }
        
        // Load draft on page load if not editing
        if(!<?php echo $is_edit_mode ? 'true' : 'false'; ?>) {
            setTimeout(loadDraft, 1000);
        }
    </script>
</body>
</html>

<?php
$mysqli->close();
?>