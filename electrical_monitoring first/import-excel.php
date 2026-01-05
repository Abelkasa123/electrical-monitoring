<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];
$message = "";
$success = false;
$imported_count = 0;
$error_log = [];

if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["excel_file"])){
    $file = $_FILES["excel_file"];
    
    // Check file type
    $allowed_ext = ['csv'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if(!in_array($file_ext, $allowed_ext)){
        $message = "Error: Only CSV files are allowed in this version. Please export your Excel file as CSV first.";
    } elseif($file['error'] !== UPLOAD_ERR_OK){
        $message = "Error uploading file. Error code: " . $file['error'];
    } else {
        try {
            // Open the CSV file
            $handle = fopen($file['tmp_name'], 'r');
            
            if($handle !== FALSE){
                // Skip header row
                fgetcsv($handle, 1000, ',');
                
                $row_number = 2;
                $imported_count = 0;
                
                while(($data = fgetcsv($handle, 1000, ',')) !== FALSE){
                    // Skip empty rows
                    if(empty(array_filter($data))){
                        $row_number++;
                        continue;
                    }
                    
                    try {
                        // Map CSV columns (adjust indices based on your CSV format)
                        // Expected: Timestamp, Voltage, Current, Active Power, Reactive Power, Frequency, Power Factor, Voltage Category
                        $timestamp = !empty($data[0]) ? date('Y-m-d H:i:s', strtotime($data[0])) : date('Y-m-d H:i:s');
                        $voltage = floatval($data[1] ?? 0);
                        $current = floatval($data[2] ?? 0);
                        $active_power = floatval($data[3] ?? 0);
                        $reactive_power = floatval($data[4] ?? 0);
                        $frequency = floatval($data[5] ?? 50);
                        $power_factor = floatval($data[6] ?? 1);
                        $category_name = $data[7] ?? '';
                        
                        // Validate data
                        if($voltage <= 0 || $current <= 0){
                            throw new Exception("Invalid voltage or current value");
                        }
                        
                        if($power_factor < 0 || $power_factor > 1){
                            throw new Exception("Power factor must be between 0 and 1");
                        }
                        
                        // Get or create voltage category
                        $category_id = null;
                        if(!empty($category_name)){
                            $cat_stmt = $mysqli->prepare("SELECT id FROM voltage_categories WHERE category_name = ?");
                            $cat_stmt->bind_param("s", $category_name);
                            $cat_stmt->execute();
                            $cat_result = $cat_stmt->get_result();
                            
                            if($cat_result->num_rows > 0){
                                $category_id = $cat_result->fetch_assoc()['id'];
                            } else {
                                // Create new category
                                $cat_insert = $mysqli->prepare("INSERT INTO voltage_categories (category_name) VALUES (?)");
                                $cat_insert->bind_param("s", $category_name);
                                if($cat_insert->execute()){
                                    $category_id = $mysqli->insert_id;
                                }
                                $cat_insert->close();
                            }
                            $cat_stmt->close();
                        }
                        
                        // Insert data
                        $sql = "INSERT INTO electrical_data 
                                (user_id, timestamp, voltage, current, active_power, reactive_power, frequency, power_factor, voltage_category_id) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $mysqli->prepare($sql);
                        $stmt->bind_param(
                            "isddddddi",
                            $user_id,
                            $timestamp,
                            $voltage,
                            $current,
                            $active_power,
                            $reactive_power,
                            $frequency,
                            $power_factor,
                            $category_id
                        );
                        
                        if($stmt->execute()){
                            $imported_count++;
                        } else {
                            throw new Exception("Database insert failed: " . $stmt->error);
                        }
                        
                        $stmt->close();
                        
                    } catch (Exception $e) {
                        $error_log[] = "Row {$row_number}: " . $e->getMessage();
                    }
                    
                    $row_number++;
                }
                
                fclose($handle);
                $success = true;
                $message = "Successfully imported {$imported_count} records.";
                
            } else {
                $message = "Error: Could not open the CSV file.";
            }
            
        } catch (Exception $e) {
            $message = "Error processing file: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import CSV Data</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .upload-area {
            border: 3px dashed #107c41;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background: #e7f6ea;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .upload-area:hover {
            background: #d4edda;
            border-color: #0d6b37;
        }
        
        .upload-area.dragover {
            background: #c3e6cb;
            border-color: #28a745;
            transform: scale(1.02);
        }
        
        .file-info {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
            display: none;
        }
        
        .template-download {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .step-icon {
            width: 40px;
            height: 40px;
            background: #107c41;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .error-log {
            max-height: 200px;
            overflow-y: auto;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-bolt"></i> Electrical Data System
            </a>
            <a href="view-data.php" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Excel View
            </a>
        </div>
    </nav>
    
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow">
                    <div class="card-header bg-excel text-white" style="background: #107c41;">
                        <h4 class="mb-0"><i class="fas fa-file-import me-2"></i> Import CSV Data</h4>
                    </div>
                    
                    <div class="card-body">
                        <?php if(!empty($message)): ?>
                            <div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?> alert-dismissible fade show">
                                <?php echo $message; ?>
                                
                                <?php if($success && $imported_count > 0): ?>
                                    <p class="mb-0 mt-2">
                                        <strong><?php echo $imported_count; ?></strong> records imported successfully.
                                        <a href="view-data.php" class="alert-link">View imported data</a>
                                    </p>
                                <?php endif; ?>
                                
                                <?php if(!empty($error_log)): ?>
                                    <div class="mt-3">
                                        <h6>Errors during import:</h6>
                                        <div class="error-log border p-2 bg-light">
                                            <?php foreach($error_log as $error): ?>
                                                <div class="text-danger mb-1"><?php echo $error; ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Template Download -->
                        <div class="template-download">
                            <h5><i class="fas fa-file-download text-primary me-2"></i> Download CSV Template</h5>
                            <p class="text-muted">Use our CSV template to ensure proper formatting.</p>
                            <a href="download-template-csv.php" class="btn btn-success">
                                <i class="fas fa-download me-2"></i> Download CSV Template
                            </a>
                            <button class="btn btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#sampleModal">
                                <i class="fas fa-eye me-2"></i> View Sample Format
                            </button>
                        </div>
                        
                        <!-- Import Steps -->
                        <div class="mb-4">
                            <h5 class="mb-3">How to import CSV:</h5>
                            <div class="d-flex mb-3">
                                <div class="step-icon">
                                    <i class="fas fa-download"></i>
                                </div>
                                <div>
                                    <h6>1. Download Template</h6>
                                    <p class="mb-0 text-muted">Download our CSV template for correct formatting.</p>
                                </div>
                            </div>
                            <div class="d-flex mb-3">
                                <div class="step-icon">
                                    <i class="fas fa-edit"></i>
                                </div>
                                <div>
                                    <h6>2. Fill Your Data</h6>
                                    <p class="mb-0 text-muted">Open the CSV in Excel or any spreadsheet software and enter your data.</p>
                                </div>
                            </div>
                            <div class="d-flex">
                                <div class="step-icon">
                                    <i class="fas fa-upload"></i>
                                </div>
                                <div>
                                    <h6>3. Upload CSV File</h6>
                                    <p class="mb-0 text-muted">Upload your filled CSV file below.</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Upload Form -->
                        <form method="POST" enctype="multipart/form-data" id="importForm">
                            <div class="upload-area" id="dropArea">
                                <input type="file" name="excel_file" id="fileInput" accept=".csv" hidden required>
                                
                                <div class="mb-3">
                                    <i class="fas fa-file-csv fa-4x text-primary mb-3"></i>
                                    <h4>Drop CSV file here or click to browse</h4>
                                    <p class="text-muted">Supports .csv files (maximum 10MB)</p>
                                </div>
                                
                                <button type="button" class="btn btn-primary btn-lg" onclick="document.getElementById('fileInput').click()">
                                    <i class="fas fa-folder-open me-2"></i> Browse Files
                                </button>
                            </div>
                            
                            <div class="file-info" id="fileInfo">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-file-csv text-primary me-2"></i>
                                        <span id="fileName"></span>
                                        <small class="text-muted ms-2" id="fileSize"></small>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearFile()">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mt-4 text-center">
                                <button type="submit" class="btn btn-success btn-lg px-5" id="submitBtn" disabled>
                                    <i class="fas fa-upload me-2"></i> Import Data
                                </button>
                                <a href="view-data.php" class="btn btn-outline-secondary btn-lg ms-2">
                                    <i class="fas fa-times me-2"></i> Cancel
                                </a>
                            </div>
                        </form>
                        
                        <!-- Column Format Info -->
                        <div class="mt-5">
                            <h5 class="mb-3"><i class="fas fa-columns me-2"></i> Required CSV Column Format</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Column</th>
                                            <th>Description</th>
                                            <th>Format</th>
                                            <th>Example</th>
                                            <th>Required</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Column 1</td>
                                            <td>Timestamp</td>
                                            <td>Date/Time</td>
                                            <td>2023-12-15 14:30:00</td>
                                            <td>Optional (defaults to now)</td>
                                        </tr>
                                        <tr>
                                            <td>Column 2</td>
                                            <td>Voltage (V)</td>
                                            <td>Number</td>
                                            <td>230.50</td>
                                            <td>Required</td>
                                        </tr>
                                        <tr>
                                            <td>Column 3</td>
                                            <td>Current (A)</td>
                                            <td>Number</td>
                                            <td>15.25</td>
                                            <td>Required</td>
                                        </tr>
                                        <tr>
                                            <td>Column 4</td>
                                            <td>Active Power (W)</td>
                                            <td>Number</td>
                                            <td>3512.75</td>
                                            <td>Required</td>
                                        </tr>
                                        <tr>
                                            <td>Column 5</td>
                                            <td>Reactive Power (VAR)</td>
                                            <td>Number</td>
                                            <td>1250.30</td>
                                            <td>Optional</td>
                                        </tr>
                                        <tr>
                                            <td>Column 6</td>
                                            <td>Frequency (Hz)</td>
                                            <td>Number</td>
                                            <td>50.00</td>
                                            <td>Optional (defaults to 50)</td>
                                        </tr>
                                        <tr>
                                            <td>Column 7</td>
                                            <td>Power Factor</td>
                                            <td>Number (0-1)</td>
                                            <td>0.95</td>
                                            <td>Optional (defaults to 1)</td>
                                        </tr>
                                        <tr>
                                            <td>Column 8</td>
                                            <td>Voltage Category</td>
                                            <td>Text</td>
                                            <td>Low Voltage</td>
                                            <td>Optional</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Important:</strong> Your CSV file must use comma (,) as delimiter and have the exact column order as shown above.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Sample Data Modal -->
    <div class="modal fade" id="sampleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sample CSV Format</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-success">
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Voltage (V)</th>
                                    <th>Current (A)</th>
                                    <th>Active Power (W)</th>
                                    <th>Reactive Power (VAR)</th>
                                    <th>Frequency (Hz)</th>
                                    <th>Power Factor</th>
                                    <th>Voltage Category</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>2023-12-15 08:00:00</td>
                                    <td>230.50</td>
                                    <td>15.25</td>
                                    <td>3512.75</td>
                                    <td>1250.30</td>
                                    <td>50.00</td>
                                    <td>0.95</td>
                                    <td>Low Voltage</td>
                                </tr>
                                <tr>
                                    <td>2023-12-15 09:00:00</td>
                                    <td>231.00</td>
                                    <td>14.80</td>
                                    <td>3418.80</td>
                                    <td>1105.60</td>
                                    <td>49.98</td>
                                    <td>0.96</td>
                                    <td>Low Voltage</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info">
                        <strong>CSV Content Preview:</strong>
                        <pre class="mt-2">
Timestamp,Voltage (V),Current (A),Active Power (W),Reactive Power (VAR),Frequency (Hz),Power Factor,Voltage Category
2023-12-15 08:00:00,230.50,15.25,3512.75,1250.30,50.00,0.95,Low Voltage
2023-12-15 09:00:00,231.00,14.80,3418.80,1105.60,49.98,0.96,Low Voltage
                        </pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="download-template-csv.php" class="btn btn-success">Download CSV Template</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const dropArea = document.getElementById('dropArea');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const submitBtn = document.getElementById('submitBtn');
        
        // Drag and drop events
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            dropArea.classList.add('dragover');
        }
        
        function unhighlight() {
            dropArea.classList.remove('dragover');
        }
        
        // Handle dropped files
        dropArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if(files.length > 0) {
                fileInput.files = files;
                updateFileInfo(files[0]);
            }
        }
        
        // Handle file selection
        fileInput.addEventListener('change', function() {
            if(this.files.length > 0) {
                updateFileInfo(this.files[0]);
            }
        });
        
        function updateFileInfo(file) {
            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            fileInfo.style.display = 'block';
            submitBtn.disabled = false;
            
            // Validate file type
            const allowedTypes = ['.csv'];
            const fileExt = '.' + file.name.split('.').pop().toLowerCase();
            
            if(!allowedTypes.includes(fileExt)) {
                alert('Error: Only CSV files are allowed in this version. Please export your Excel file as CSV first.');
                clearFile();
            }
            
            // Check file size (10MB limit)
            if(file.size > 10 * 1024 * 1024) {
                alert('Error: File size exceeds 10MB limit.');
                clearFile();
            }
        }
        
        function formatFileSize(bytes) {
            if(bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        function clearFile() {
            fileInput.value = '';
            fileInfo.style.display = 'none';
            submitBtn.disabled = true;
        }
        
        // Form submission loading state
        document.getElementById('importForm').addEventListener('submit', function() {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Importing...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>