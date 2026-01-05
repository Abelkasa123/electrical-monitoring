<?php
session_start();
require_once "config/database.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];
$search = $date_filter = $voltage_min = $voltage_max = "";
$where_conditions = ["user_id = ?"];
$params = [$user_id];
$param_types = "i";

// Handle search and filters
if($_SERVER["REQUEST_METHOD"] == "GET"){
    if(!empty($_GET['search'])){
        $search = $_GET['search'];
        $where_conditions[] = "(voltage LIKE ? OR current LIKE ? OR active_power LIKE ? OR frequency LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $param_types .= "ssss";
    }
    
    if(!empty($_GET['date_filter'])){
        $date_filter = $_GET['date_filter'];
        if($date_filter == 'today'){
            $where_conditions[] = "DATE(timestamp) = CURDATE()";
        } elseif($date_filter == 'yesterday'){
            $where_conditions[] = "DATE(timestamp) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } elseif($date_filter == 'week'){
            $where_conditions[] = "timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif($date_filter == 'month'){
            $where_conditions[] = "timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }
    }
    
    if(!empty($_GET['voltage_min'])){
        $voltage_min = $_GET['voltage_min'];
        $where_conditions[] = "voltage >= ?";
        $params[] = $voltage_min;
        $param_types .= "d";
    }
    
    if(!empty($_GET['voltage_max'])){
        $voltage_max = $_GET['voltage_max'];
        $where_conditions[] = "voltage <= ?";
        $params[] = $voltage_max;
        $param_types .= "d";
    }
}

// Build WHERE clause
$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "WHERE user_id = ?";

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM electrical_data $where_sql";
$count_stmt = $mysqli->prepare($count_sql);

if(count($params) > 0){
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'] ?? 0;
$count_stmt->close();

// Show all records without pagination (for Excel view)
$sql = "SELECT e.*, vc.category_name 
        FROM electrical_data e 
        LEFT JOIN voltage_categories vc ON e.voltage_category_id = vc.id 
        $where_sql 
        ORDER BY e.timestamp DESC";

$stmt = $mysqli->prepare($sql);
if(count($params) > 0){
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get voltage categories for dropdown
$voltage_categories = [];
$cat_sql = "SELECT id, category_name FROM voltage_categories ORDER BY id";
$cat_result = $mysqli->query($cat_sql);
while($cat_row = $cat_result->fetch_assoc()){
    $voltage_categories[$cat_row['id']] = $cat_row['category_name'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excel View - Electrical Data</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --excel-green: #107c41;
            --excel-light: #e7f6ea;
        }
        
        .excel-header {
            background: var(--excel-green);
            color: white;
            padding: 15px 0;
            margin-bottom: 20px;
        }
        
        .excel-toolbar {
            background: white;
            padding: 10px 15px;
            border-bottom: 2px solid #dee2e6;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .excel-table {
            background: white;
            border: 1px solid #dee2e6;
            margin-bottom: 20px;
            overflow-x: auto;
        }
        
        .excel-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        .excel-table th {
            background-color: var(--excel-light);
            border: 1px solid #dee2e6;
            padding: 8px 12px;
            font-weight: 600;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .excel-table td {
            border: 1px solid #dee2e6;
            padding: 6px 10px;
            vertical-align: middle;
        }
        
        .excel-table tr:hover {
            background-color: rgba(16, 124, 65, 0.05);
        }
        
        .excel-table tr.editing {
            background-color: #fff3cd !important;
        }
        
        .cell-input {
            width: 100%;
            border: 1px solid #86b7fe;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 0.9rem;
        }
        
        .cell-input:focus {
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(16, 124, 65, 0.25);
        }
        
        .btn-excel {
            background: var(--excel-green);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 3px;
            font-size: 0.9rem;
        }
        
        .btn-excel:hover {
            background: #0d6b37;
            color: white;
        }
        
        .cell-dropdown {
            width: 100%;
            padding: 4px 8px;
            border: 1px solid #86b7fe;
            border-radius: 3px;
            font-size: 0.9rem;
            background: white;
        }
        
        .selected-row {
            background-color: rgba(16, 124, 65, 0.1) !important;
        }
        
        .checkbox-cell {
            width: 30px;
            text-align: center;
        }
        
        .status-badge {
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.75rem;
        }
        
        .status-saved { background: #d1e7dd; color: #0f5132; }
        .status-pending { background: #fff3cd; color: #856404; }
        
        .filter-section {
            background: white;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            margin-bottom: 15px;
        }
        
        .cell-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .cell-badge.voltage { background: #e3f2fd; color: #1565c0; }
        .cell-badge.current { background: #f3e5f5; color: #7b1fa2; }
        .cell-badge.power { background: #e8f5e9; color: #2e7d32; }
        .cell-badge.frequency { background: #fff3e0; color: #ef6c00; }
    </style>
</head>
<body>
    <!-- Excel-like Header -->
    <div class="excel-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0"><i class="fas fa-file-excel"></i> Electrical Data - Excel View</h4>
                    <small class="opacity-75">Edit, filter, and manage your data in spreadsheet format</small>
                </div>
                <div class="d-flex gap-2">
                    <a href="dashboard.php" class="btn btn-light btn-sm">
                        <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                    </a>
                    <a href="data-entry.php" class="btn btn-light btn-sm">
                        <i class="fas fa-plus me-1"></i> Add New
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container-fluid">
        <!-- Formula Bar -->
        <div class="bg-light p-2 border-bottom">
            <div class="d-flex align-items-center">
                <span class="me-2 text-muted">Ready</span>
                <span id="formulaDisplay" class="ms-2 font-monospace"></span>
            </div>
        </div>
        
        <!-- Toolbar -->
        <div class="excel-toolbar">
            <div class="d-flex gap-2">
                <button class="btn btn-excel" onclick="saveAllChanges()">
                    <i class="fas fa-save me-1"></i> Save All
                </button>
                <button class="btn btn-outline-primary" onclick="addNewRow()">
                    <i class="fas fa-plus me-1"></i> Add Row
                </button>
                <button class="btn btn-outline-danger" onclick="deleteSelectedRows()">
                    <i class="fas fa-trash me-1"></i> Delete Selected
                </button>
                <button class="btn btn-outline-success" onclick="exportToExcel()">
                    <i class="fas fa-download me-1"></i> Export Excel
                </button>
                <button class="btn btn-outline-secondary" onclick="printTable()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
                 <button class="btn btn-outline-secondary" onclick="importExcel()">
                    <i class="fas fa-file-import me-1"></i> Import Excel
                </button>
            </div>
            
            <div class="ms-auto d-flex align-items-center gap-2">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="editModeToggle" checked>
                    <label class="form-check-label" for="editModeToggle">Edit Mode</label>
                </div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" action="" class="row g-2">
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" name="search" placeholder="Search all columns..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                
                <div class="col-md-3">
                    <select class="form-select form-select-sm" name="date_filter">
                        <option value="">All Dates</option>
                        <option value="today" <?php echo $date_filter == 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="yesterday" <?php echo $date_filter == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                        <option value="week" <?php echo $date_filter == 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                        <option value="month" <?php echo $date_filter == 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control" name="voltage_min" placeholder="Min Voltage" 
                               value="<?php echo $voltage_min; ?>">
                        <span class="input-group-text">to</span>
                        <input type="number" class="form-control" name="voltage_max" placeholder="Max Voltage" 
                               value="<?php echo $voltage_max; ?>">
                    </div>
                </div>
                
                <div class="col-md-2">
                    <div class="d-flex gap-1">
                        <button type="submit" class="btn btn-excel btn-sm">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                        <a href="view-data.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Stats Bar -->
        <div class="bg-white p-2 border">
            <div class="d-flex justify-content-between">
                <div>
                    <span id="selectedCount">0</span> of <span id="totalCount"><?php echo $total_records; ?></span> records selected
                    <span class="mx-2">|</span>
                    <span id="editCount">0</span> unsaved changes
                </div>
                <div>
                    <span class="badge bg-info">
                        <i class="fas fa-database me-1"></i> <?php echo $total_records; ?> total records
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Excel Table -->
        <div class="excel-table">
            <div style="max-height: 600px; overflow-y: auto;">
                <table id="excelDataTable">
                    <thead>
                        <tr>
                            <th class="checkbox-cell">
                                <input type="checkbox" id="selectAllRows" onchange="toggleAllRows()">
                            </th>
                            <th>ID</th>
                            <th>Timestamp</th>
                            <th>Voltage (V)</th>
                            <th>Current (A)</th>
                            <th>Active Power (W)</th>
                            <th>Reactive Power (VAR)</th>
                            <th>Frequency (Hz)</th>
                            <th>Power Factor</th>
                            <th>Voltage Category</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="dataTableBody">
                        <?php if($result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                            <tr data-id="<?php echo $row['id']; ?>" data-original='<?php echo json_encode($row); ?>'>
                                <td class="checkbox-cell">
                                    <input type="checkbox" class="row-selector" value="<?php echo $row['id']; ?>">
                                </td>
                                
                                <td data-column="id" data-value="<?php echo $row['id']; ?>" data-editable="false">
                                    <span class="badge bg-secondary">#<?php echo $row['id']; ?></span>
                                </td>
                                
                                <td data-column="timestamp" data-value="<?php echo $row['timestamp']; ?>" data-editable="false">
                                    <small class="text-muted"><?php echo date('Y-m-d H:i:s', strtotime($row['timestamp'])); ?></small>
                                </td>
                                
                                <td data-column="voltage" data-value="<?php echo $row['voltage']; ?>" data-editable="true" class="editable-cell">
                                    <span class="cell-badge voltage"><?php echo number_format($row['voltage'], 2); ?></span>
                                </td>
                                
                                <td data-column="current" data-value="<?php echo $row['current']; ?>" data-editable="true" class="editable-cell">
                                    <span class="cell-badge current"><?php echo number_format($row['current'], 2); ?></span>
                                </td>
                                
                                <td data-column="active_power" data-value="<?php echo $row['active_power']; ?>" data-editable="true" class="editable-cell">
                                    <span class="cell-badge power"><?php echo number_format($row['active_power'], 2); ?></span>
                                </td>
                                
                                <td data-column="reactive_power" data-value="<?php echo $row['reactive_power']; ?>" data-editable="true" class="editable-cell">
                                    <span class="cell-badge power"><?php echo number_format($row['reactive_power'], 2); ?></span>
                                </td>
                                
                                <td data-column="frequency" data-value="<?php echo $row['frequency']; ?>" data-editable="true" class="editable-cell">
                                    <span class="cell-badge frequency"><?php echo number_format($row['frequency'], 2); ?></span>
                                </td>
                                
                                <td data-column="power_factor" data-value="<?php echo $row['power_factor']; ?>" data-editable="true" class="editable-cell">
                                    <?php $pf_class = $row['power_factor'] >= 0.9 ? 'text-success' : ($row['power_factor'] >= 0.8 ? 'text-warning' : 'text-danger'); ?>
                                    <span class="<?php echo $pf_class; ?>"><?php echo number_format($row['power_factor'], 3); ?></span>
                                </td>
                                
                                <td data-column="voltage_category_id" data-value="<?php echo $row['voltage_category_id']; ?>" data-editable="true" class="editable-cell">
                                    <?php if(!empty($row['category_name'])): ?>
                                        <span class="badge bg-info"><?php echo $row['category_name']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="editRow(<?php echo $row['id']; ?>)"
                                                title="Edit Row">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteRow(<?php echo $row['id']; ?>)"
                                                title="Delete Row">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <span class="status-badge status-saved" style="display: none;">Saved</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-database fa-3x mb-3"></i>
                                        <h5>No electrical data found</h5>
                                        <p class="mb-0">
                                            <a href="data-entry.php" class="btn btn-excel btn-sm">
                                                <i class="fas fa-plus me-1"></i> Add New Data
                                            </a>
                                            or
                                            <a href="import-export.php" class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-file-import me-1"></i> Import from Excel
                                            </a>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Bulk Actions -->
        <div class="bg-white p-3 border-top">
            <div class="d-flex justify-content-between align-items-center">
                <div class="btn-group">
                    <button class="btn btn-excel btn-sm" onclick="saveSelectedRows()">
                        <i class="fas fa-save me-1"></i> Save Selected
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="duplicateSelectedRows()">
                        <i class="fas fa-copy me-1"></i> Duplicate
                    </button>
                </div>
                <div>
                    <button class="btn btn-outline-secondary btn-sm" onclick="window.scrollTo(0,0)">
                        <i class="fas fa-arrow-up me-1"></i> Top
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script>
        // Global variables
        let unsavedChanges = new Set();
        let selectedRows = new Set();
        let editingCell = null;
        let editingRowId = null;
        
        // Initialize
        $(document).ready(function() {
            updateStats();
            enableEditMode();
            
            // Keyboard shortcuts
            $(document).keydown(function(e) {
                // Ctrl+S to save
                if(e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    saveAllChanges();
                }
                
                // Escape to cancel editing
                if(e.key === 'Escape' && editingCell) {
                    cancelEdit(editingCell);
                }
                
                // Enter to save and move down
                if(e.key === 'Enter' && editingCell) {
                    saveCellEdit(editingCell);
                    moveToNextCell(editingCell);
                }
            });
            
            // Click outside to save
            $(document).click(function(e) {
                if(editingCell && !$(e.target).closest('.cell-input, .cell-dropdown').length) {
                    saveCellEdit(editingCell);
                }
            });
            
            // Update selected count
            $('.row-selector').change(function() {
                const rowId = $(this).val();
                if(this.checked) {
                    selectedRows.add(rowId);
                    $(this).closest('tr').addClass('selected-row');
                } else {
                    selectedRows.delete(rowId);
                    $(this).closest('tr').removeClass('selected-row');
                }
                updateStats();
            });
            
            // Toggle edit mode
            $('#editModeToggle').change(function() {
                if(this.checked) {
                    enableEditMode();
                } else {
                    disableEditMode();
                }
            });
        });
        
        function enableEditMode() {
            $('.editable-cell')
                .addClass('text-primary')
                .css('cursor', 'pointer')
                .attr('title', 'Click to edit');
        }
        
        function disableEditMode() {
            $('.editable-cell')
                .removeClass('text-primary')
                .css('cursor', 'default')
                .removeAttr('title');
            
            // Save any active edit when disabling
            if(editingCell) {
                saveCellEdit(editingCell);
            }
        }// Edit row function - FIXED VERSION
function editRow(rowId) {
    const row = $(`tr[data-id="${rowId}"]`);
    
    // If we're already editing this row, navigate to edit form
    if(editingRowId === rowId) {
        window.location.href = `data-entry.php?edit=${rowId}`;
        return;
    }
    
    // If editing another row, save it first
    if(editingRowId) {
        saveAllCellEditsInRow(editingRowId);
        $(`tr[data-id="${editingRowId}"]`).removeClass('editing')
            .find('.btn-outline-primary i').removeClass('fa-check').addClass('fa-edit');
    }
    
    // Enter inline edit mode for this row
    editingRowId = rowId;
    row.addClass('editing');
    row.find('.btn-outline-primary i').removeClass('fa-edit').addClass('fa-check');
    
    // Enable edit mode toggle if not enabled
    if(!$('#editModeToggle').prop('checked')) {
        $('#editModeToggle').prop('checked', true).trigger('change');
    }
    
    // Update edit button to show "Open Form" option
    const editBtn = row.find('.btn-outline-primary');
    editBtn.attr('title', 'Click to open edit form')
           .attr('onclick', `openEditForm(${rowId})`)
           .html('<i class="fas fa-external-link-alt"></i>');
    
    // Add a new button for inline editing
    editBtn.after(`
        <button class="btn btn-sm btn-outline-warning" 
                onclick="enableInlineEdit(${rowId})"
                title="Inline Edit">
            <i class="fas fa-edit"></i>
        </button>
    `);
    
    // Auto-enable inline edit after a short delay
    setTimeout(() => enableInlineEdit(rowId), 300);
}

// New function to enable inline editing for a row
function enableInlineEdit(rowId) {
    const row = $(`tr[data-id="${rowId}"]`);
    
    // Remove any existing input fields
    row.find('.cell-input, .cell-dropdown').each(function() {
        saveCellEdit(this.parentNode);
    });
    
    // Make all editable cells clickable
    row.find('.editable-cell').each(function() {
        $(this).click(function() {
            editCell(this);
        });
    });
    
    // Focus on first editable cell
    const firstCell = row.find('.editable-cell').first();
    if(firstCell.length) {
        setTimeout(() => editCell(firstCell[0]), 100);
    }
}

// Function to open edit form
function openEditForm(rowId) {
    // First save any unsaved changes
    if(unsavedChanges.size > 0) {
        if(confirm('You have unsaved changes. Save before opening edit form?')) {
            saveAllCellEditsInRow(rowId);
            setTimeout(() => {
                window.location.href = `data-entry.php?edit=${rowId}`;
            }, 500);
            return;
        }
    }
    window.location.href = `data-entry.php?edit=${rowId}`;
}
        // Cell editing functions
        function editCell(cell) {
            if(!$('#editModeToggle').prop('checked')) return;
            if($(cell).data('editable') !== 'true') return;
            
            // If clicking on a cell in a different row than currently editing,
            // automatically enable edit mode for that row
            const rowId = $(cell).closest('tr').data('id');
            if(editingRowId !== rowId) {
                editRow(rowId);
                return;
            }
            
            // Save any previous edit
            if(editingCell) {
                saveCellEdit(editingCell);
            }
            
            const column = $(cell).data('column');
            const value = $(cell).data('value');
            
            let inputHtml = '';
            
            // Create appropriate input based on column type
            if(column === 'voltage_category_id') {
                // Dropdown for voltage category
                inputHtml = `
                    <select class="cell-dropdown" onchange="saveCellEdit(this.parentNode)" 
                            onblur="saveCellEdit(this.parentNode)">
                        <option value="">Select Category</option>
                        <?php foreach($voltage_categories as $id => $name): ?>
                        <option value="<?php echo $id; ?>" ${value == '<?php echo $id; ?>' ? 'selected' : ''}>
                            <?php echo htmlspecialchars($name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                `;
            } else if(column === 'power_factor') {
                // Number input with range for power factor
                inputHtml = `
                    <input type="number" class="cell-input" value="${value}" 
                           step="0.001" min="0" max="1"
                           onblur="saveCellEdit(this.parentNode)">
                `;
            } else if(['voltage', 'current', 'active_power', 'reactive_power', 'frequency'].includes(column)) {
                // Number input for numerical values
                const step = column === 'frequency' ? '0.01' : '0.01';
                inputHtml = `
                    <input type="number" class="cell-input" value="${value}" 
                           step="${step}"
                           onblur="saveCellEdit(this.parentNode)">
                `;
            }
            
            cell.innerHTML = inputHtml;
            const input = cell.querySelector('input, select');
            input.focus();
            
            if(input.type !== 'select-one') {
                input.select();
            }
            
            editingCell = cell;
            
            // Update formula bar
            $('#formulaDisplay').text(`${column}: ${value}`);
        }
        
        function saveCellEdit(cell) {
            if(!cell || !cell.querySelector('input, select')) return;
            
            const input = cell.querySelector('input, select');
            const newValue = input.value;
            const column = $(cell).data('column');
            const rowId = $(cell).closest('tr').data('id');
            const originalValue = $(cell).data('value');
            
            // Validate input
            if(column === 'power_factor' && (newValue < 0 || newValue > 1)) {
                alert('Power factor must be between 0 and 1');
                input.focus();
                return;
            }
            
            // Check if value changed
            if(newValue != originalValue) {
                // Mark as changed
                const changeId = `${rowId}-${column}`;
                unsavedChanges.add(changeId);
                
                // Update cell display
                updateCellDisplay(cell, column, newValue);
                
                // Update data attribute
                $(cell).data('value', newValue);
                
                // Show save indicator
                const statusBadge = $(cell).closest('tr').find('.status-badge');
                statusBadge.removeClass('status-saved').addClass('status-pending').text('Pending').show();
                
                updateStats();
            }
            
            editingCell = null;
            $('#formulaDisplay').text('');
        }
        
        function cancelEdit(cell) {
            if(!cell || !cell.querySelector('input, select')) return;
            
            const originalValue = $(cell).data('value');
            const column = $(cell).data('column');
            
            // Restore original display
            updateCellDisplay(cell, column, originalValue);
            
            editingCell = null;
            $('#formulaDisplay').text('');
        }
        
        function updateCellDisplay(cell, column, value) {
            let displayHtml = '';
            
            if(column === 'voltage') {
                displayHtml = `<span class="cell-badge voltage">${parseFloat(value).toFixed(2)}</span>`;
            } else if(column === 'current') {
                displayHtml = `<span class="cell-badge current">${parseFloat(value).toFixed(2)}</span>`;
            } else if(column === 'active_power' || column === 'reactive_power') {
                displayHtml = `<span class="cell-badge power">${parseFloat(value).toFixed(2)}</span>`;
            } else if(column === 'frequency') {
                displayHtml = `<span class="cell-badge frequency">${parseFloat(value).toFixed(2)}</span>`;
            } else if(column === 'power_factor') {
                const pfClass = value >= 0.9 ? 'text-success' : (value >= 0.8 ? 'text-warning' : 'text-danger');
                displayHtml = `<span class="${pfClass}">${parseFloat(value).toFixed(3)}</span>`;
            } else if(column === 'voltage_category_id') {
                if(value && <?php echo json_encode($voltage_categories); ?>[value]) {
                    displayHtml = `<span class="badge bg-info"><?php echo json_encode($voltage_categories); ?>[value]</span>`;
                } else {
                    displayHtml = `<span class="text-muted">Not set</span>`;
                }
            }
            
            cell.innerHTML = displayHtml;
        }
        
        function moveToNextCell(currentCell) {
            const nextCell = $(currentCell).next('td.editable-cell')[0];
            if(nextCell) {
                setTimeout(() => editCell(nextCell), 10);
            }
        }
        
        // Delete single row
        function deleteRow(rowId) {
            if(editingRowId === rowId) {
                alert('Please save or cancel edits before deleting this row.');
                return;
            }
            
            if(!confirm('Are you sure you want to delete this record?')) return;
            
            const row = $(`tr[data-id="${rowId}"]`);
            row.addClass('table-danger');
            
            fetch('delete-data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'id=' + rowId
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    // Remove row with animation
                    row.fadeOut(300, function() {
                        row.remove();
                        if(selectedRows.has(rowId)) {
                            selectedRows.delete(rowId);
                        }
                        updateStats();
                        alert('Record deleted successfully!');
                    });
                } else {
                    row.removeClass('table-danger');
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                row.removeClass('table-danger');
                alert('Network error. Please try again.');
            });
        }
        
        // Bulk operations
        function toggleAllRows() {
            const selectAll = $('#selectAllRows').prop('checked');
            $('.row-selector').prop('checked', selectAll).trigger('change');
            
            // Update row selection
            selectedRows.clear();
            if(selectAll) {
                $('.row-selector').each(function() {
                    selectedRows.add($(this).val());
                });
            }
            
            updateStats();
        }
        
        function deleteSelectedRows() {
            if(selectedRows.size === 0) {
                alert('Please select at least one row to delete.');
                return;
            }
            
            // Check if any selected row is being edited
            for(let rowId of selectedRows) {
                if(editingRowId === rowId) {
                    alert('Cannot delete row while it is being edited.');
                    return;
                }
            }
            
            if(!confirm(`Are you sure you want to delete ${selectedRows.size} selected record(s)?`)) return;
            
            // Show loading
            showLoading('Deleting records...');
            
            fetch('delete-data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ids=' + JSON.stringify(Array.from(selectedRows))
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if(data.success) {
                    // Remove selected rows
                    $('.row-selector:checked').closest('tr').each(function() {
                        const rowId = $(this).data('id');
                        if(selectedRows.has(rowId)) {
                            $(this).fadeOut(300, function() {
                                $(this).remove();
                            });
                        }
                    });
                    
                    // Clear selection
                    selectedRows.clear();
                    $('.row-selector').prop('checked', false);
                    
                    setTimeout(() => {
                        updateStats();
                        alert(`${data.deleted_count} record(s) deleted successfully!`);
                    }, 500);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                hideLoading();
                alert('Network error. Please try again.');
            });
        }
        
        function saveSelectedRows() {
            if(selectedRows.size === 0) {
                alert('Please select at least one row to save.');
                return;
            }
            
            // First save any active cell edits
            if(editingCell) {
                saveCellEdit(editingCell);
            }
            
            saveChangesForRows(Array.from(selectedRows));
        }
        
        function saveAllChanges() {
            // First save any active cell edit
            if(editingCell) {
                saveCellEdit(editingCell);
            }
            
            if(unsavedChanges.size === 0) {
                alert('No changes to save.');
                return;
            }
            
            // Get all rows with changes
            const changedRows = new Set();
            unsavedChanges.forEach(changeId => {
                const [rowId] = changeId.split('-');
                changedRows.add(rowId);
            });
            
            saveChangesForRows(Array.from(changedRows));
        }
        
        function saveChangesForRows(rowIds) {
            const changes = [];
            
            rowIds.forEach(rowId => {
                const row = $(`tr[data-id="${rowId}"]`);
                const rowData = {};
                let hasChanges = false;
                
                // Check if this row has unsaved changes
                row.find('td.editable-cell').each(function() {
                    const column = $(this).data('column');
                    const changeId = `${rowId}-${column}`;
                    if(unsavedChanges.has(changeId)) {
                        const value = $(this).data('value');
                        rowData[column] = value;
                        hasChanges = true;
                    }
                });
                
                if(hasChanges) {
                    // Check if this is a new row
                    if(rowId.toString().startsWith('new-')) {
                        rowData['new'] = true;
                    } else {
                        rowData['id'] = rowId;
                    }
                    changes.push(rowData);
                }
            });
            
            if(changes.length === 0) {
                alert('No changes found to save.');
                return;
            }
            
            showLoading(`Saving ${changes.length} record(s)...`);
            
            // Send as JSON to the API
            fetch('edit-data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(changes)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if(data.success) {
                    // Clear unsaved changes for these rows
                    rowIds.forEach(rowId => {
                        // Remove all changes for this row
                        unsavedChanges.forEach(changeId => {
                            if(changeId.startsWith(`${rowId}-`)) {
                                unsavedChanges.delete(changeId);
                            }
                        });
                        
                        // Update status badge
                        $(`tr[data-id="${rowId}"]`).find('.status-badge')
                            .removeClass('status-pending')
                            .addClass('status-saved')
                            .text('Saved')
                            .delay(2000)
                            .fadeOut(1000);
                            
                        // If this row was being edited, exit edit mode
                        if(editingRowId === rowId) {
                            $(`tr[data-id="${rowId}"]`).removeClass('editing')
                                .find('.btn-outline-primary i').removeClass('fa-check').addClass('fa-edit');
                            editingRowId = null;
                        }
                        
                        // If this was a new row, reload to get the actual ID
                        if(rowId.toString().startsWith('new-')) {
                            setTimeout(() => location.reload(), 1000);
                        }
                    });
                    
                    updateStats();
                    alert(data.message || `${data.saved_count || data.updated_count || changes.length} record(s) saved successfully!`);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                hideLoading();
                alert('Network error. Please try again.');
            });
        }
        
        function duplicateSelectedRows() {
            if(selectedRows.size === 0) {
                alert('Please select at least one row to duplicate.');
                return;
            }
            
            // First save any active edits
            if(editingCell) {
                saveCellEdit(editingCell);
            }
            
            showLoading('Duplicating records...');
            
            // Get selected rows data
            const rowsToDuplicate = [];
            $('.row-selector:checked').each(function() {
                const row = $(this).closest('tr');
                const rowData = JSON.parse(row.data('original'));
                // Remove ID and timestamp for new record
                delete rowData.id;
                delete rowData.timestamp;
                rowsToDuplicate.push(rowData);
            });
            
            // Send to server for duplication
            fetch('edit-data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(rowsToDuplicate.map(data => ({...data, new: true})))
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if(data.success) {
                    // Reload page to show duplicated rows
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                hideLoading();
                alert('Network error. Please try again.');
            });
        }
        
        function addNewRow() {
            // First save any active edit
            if(editingCell) {
                saveCellEdit(editingCell);
            }
            
            // Exit any row edit mode
            if(editingRowId) {
                $(`tr[data-id="${editingRowId}"]`).removeClass('editing')
                    .find('.btn-outline-primary i').removeClass('fa-check').addClass('fa-edit');
                editingRowId = null;
            }
            
            const tableBody = $('#dataTableBody');
            const newRowId = 'new-' + Date.now();
            
            // Create new row
            const newRow = `
                <tr data-id="${newRowId}" data-original='{}' class="table-success">
                    <td class="checkbox-cell">
                        <input type="checkbox" class="row-selector" value="${newRowId}" onchange="$(this).closest('tr').toggleClass('selected-row', this.checked)">
                    </td>
                    <td data-column="id" data-value="" data-editable="false">
                        <span class="badge bg-secondary">New</span>
                    </td>
                    <td data-column="timestamp" data-value="<?php echo date('Y-m-d H:i:s'); ?>" data-editable="false">
                        <small class="text-muted"><?php echo date('Y-m-d H:i:s'); ?></small>
                    </td>
                    <td data-column="voltage" data-value="230.00" data-editable="true" class="editable-cell">
                        <span class="cell-badge voltage">230.00</span>
                    </td>
                    <td data-column="current" data-value="10.00" data-editable="true" class="editable-cell">
                        <span class="cell-badge current">10.00</span>
                    </td>
                    <td data-column="active_power" data-value="2300.00" data-editable="true" class="editable-cell">
                        <span class="cell-badge power">2300.00</span>
                    </td>
                    <td data-column="reactive_power" data-value="0.00" data-editable="true" class="editable-cell">
                        <span class="cell-badge power">0.00</span>
                    </td>
                    <td data-column="frequency" data-value="50.00" data-editable="true" class="editable-cell">
                        <span class="cell-badge frequency">50.00</span>
                    </td>
                    <td data-column="power_factor" data-value="1.000" data-editable="true" class="editable-cell">
                        <span class="text-success">1.000</span>
                    </td>
                    <td data-column="voltage_category_id" data-value="2" data-editable="true" class="editable-cell">
                        <span class="badge bg-info">Low Voltage</span>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-success" onclick="saveNewRow(this)">
                                <i class="fas fa-check"></i> Save
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="cancelNewRow(this)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            
            tableBody.prepend(newRow);
            alert('New row added. Click on any cell to edit values, then click Save.');
        }
        
        function saveNewRow(button) {
            const row = $(button).closest('tr');
            const rowData = {};
            
            // Collect data from editable cells
            row.find('td.editable-cell').each(function() {
                const column = $(this).data('column');
                const value = $(this).data('value');
                rowData[column] = value;
            });
            
            // Add timestamp
            rowData['timestamp'] = row.find('td[data-column="timestamp"]').data('value');
            
            showLoading('Saving new record...');
            
            fetch('edit-data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify([{
                    ...rowData,
                    new: true
                }])
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if(data.success) {
                    // Reload to show saved record
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                hideLoading();
                alert('Network error. Please try again.');
            });
        }
        
        function cancelNewRow(button) {
            $(button).closest('tr').remove();
            alert('New row cancelled.');
        }
        function importExcel() {
    const modal = new bootstrap.Modal(document.getElementById('importExcelModal'));
    modal.show();
}

function startQuickImport() {
    const form = document.getElementById('quickImportForm');
    const formData = new FormData(form);
    const importBtn = document.getElementById('importBtn');
    const progressDiv = document.getElementById('importProgress');
    const resultDiv = document.getElementById('importResult');
    const progressBar = progressDiv.querySelector('.progress-bar');
    const progressText = document.getElementById('progressText');
    
    // Validate file
    const fileInput = form.querySelector('input[type="file"]');
    if(!fileInput.files.length) {
        alert('Please select a file to import.');
        return;
    }
    
    // Disable form and show progress
    importBtn.disabled = true;
    progressDiv.classList.remove('d-none');
    resultDiv.classList.add('d-none');
    progressBar.style.width = '0%';
    progressText.textContent = 'Uploading file...';
    
    // Create XMLHttpRequest for progress tracking
    const xhr = new XMLHttpRequest();
    
    // Track upload progress
    xhr.upload.addEventListener('progress', function(e) {
        if(e.lengthComputable) {
            const percentComplete = (e.loaded / e.total) * 100;
            progressBar.style.width = percentComplete + '%';
            progressText.textContent = `Uploading: ${Math.round(percentComplete)}%`;
        }
    });
    
    // Handle completion
    xhr.onload = function() {
        if(xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                
                if(response.success) {
                    progressBar.classList.remove('progress-bar-animated');
                    progressBar.classList.add('bg-success');
                    progressText.textContent = 'Complete!';
                    
                    // Show result
                    resultDiv.innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Import successful!</strong><br>
                            Records imported: ${response.imported_count}<br>
                            Records skipped: ${response.skipped_count}<br>
                            ${response.errors.length > 0 ? 
                                `<button class="btn btn-sm btn-warning mt-2" onclick="showImportErrors(${JSON.stringify(response.errors)})">
                                    <i class="fas fa-exclamation-triangle me-1"></i> View ${response.errors.length} errors
                                </button>` : 
                                ''
                            }
                        </div>
                    `;
                    resultDiv.classList.remove('d-none');
                    
                    // Reload data after 3 seconds
                    setTimeout(() => {
                        location.reload();
                    }, 3000);
                } else {
                    resultDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-times-circle me-2"></i>
                            <strong>Import failed!</strong><br>
                            ${response.message}
                        </div>
                    `;
                    resultDiv.classList.remove('d-none');
                }
            } catch(e) {
                resultDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle me-2"></i>
                        <strong>Error parsing response!</strong><br>
                        ${xhr.responseText}
                    </div>
                `;
                resultDiv.classList.remove('d-none');
            }
        } else {
            resultDiv.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle me-2"></i>
                    <strong>Upload failed!</strong><br>
                    Server error: ${xhr.status}
                </div>
            `;
            resultDiv.classList.remove('d-none');
        }
        
        importBtn.disabled = false;
        progressDiv.classList.add('d-none');
    };
    
    // Handle errors
    xhr.onerror = function() {
        resultDiv.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-times-circle me-2"></i>
                <strong>Network error!</strong><br>
                Please check your connection and try again.
            </div>
        `;
        resultDiv.classList.remove('d-none');
        importBtn.disabled = false;
        progressDiv.classList.add('d-none');
    };
    
    // Send request
    xhr.open('POST', 'quick-import.php');
    xhr.send(formData);
}

function showImportErrors(errors) {
    const errorList = errors.map(error => `<li>${error}</li>`).join('');
    alert(`Import Errors:\n\n${errors.join('\n')}`);
}
        // Export and utility functions
        function exportToExcel() {
            window.location.href = 'export-excel.php?' + window.location.search;
        }
        
        function printTable() {
            const printContent = document.querySelector('.excel-table').outerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Electrical Data Report</title>
                        <style>
                            body { font-family: Arial, sans-serif; }
                            table { border-collapse: collapse; width: 100%; }
                            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                            th { background-color: #e7f6ea; }
                        </style>
                    </head>
                    <body>
                        <h2>Electrical Data Report</h2>
                        <p>Generated: ${new Date().toLocaleString()}</p>
                        ${printContent}
                    </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        // Stats and UI functions
        function updateStats() {
            $('#selectedCount').text(selectedRows.size);
            $('#editCount').text(unsavedChanges.size);
            $('#totalCount').text(<?php echo $total_records; ?>);
        }
        
        function showLoading(message) {
            // Create loading overlay
            const overlay = $(`
                <div id="loadingOverlay" style="
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.7);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    z-index: 99999;
                    color: white;
                ">
                    <div class="text-center">
                        <div class="spinner-border mb-3" style="width: 3rem; height: 3rem;"></div>
                        <div style="font-size: 1.2rem;">${message}</div>
                    </div>
                </div>
            `);
            
            $('body').append(overlay);
        }
        
        function hideLoading() {
            $('#loadingOverlay').remove();
        }
        
        // Auto-save changes after 30 seconds of inactivity
        let saveTimer;
        $(document).on('input change', '.cell-input, .cell-dropdown', function() {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => {
                if(unsavedChanges.size > 0) {
                    saveAllChanges();
                }
            }, 30000);
        });
    </script>
    <!-- Import Excel Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-excel text-white">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i> Import Excel File</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Import Excel/CSV files with electrical data. The file should have the following columns:
                </div>
                
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Column</th>
                                <th>Description</th>
                                <th>Format</th>
                                <th>Required</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Timestamp</td>
                                <td>Date and time of reading</td>
                                <td>YYYY-MM-DD HH:MM:SS</td>
                                <td>No</td>
                            </tr>
                            <tr>
                                <td>Voltage (V)</td>
                                <td>Voltage in volts</td>
                                <td>Number</td>
                                <td>Yes</td>
                            </tr>
                            <tr>
                                <td>Current (A)</td>
                                <td>Current in amperes</td>
                                <td>Number</td>
                                <td>Yes</td>
                            </tr>
                            <tr>
                                <td>Active Power (W)</td>
                                <td>Active power in watts</td>
                                <td>Number</td>
                                <td>Yes</td>
                            </tr>
                            <tr>
                                <td>Reactive Power (VAR)</td>
                                <td>Reactive power in VAR</td>
                                <td>Number</td>
                                <td>No</td>
                            </tr>
                            <tr>
                                <td>Frequency (Hz)</td>
                                <td>Frequency in hertz</td>
                                <td>Number</td>
                                <td>No</td>
                            </tr>
                            <tr>
                                <td>Power Factor</td>
                                <td>Power factor (0-1)</td>
                                <td>Number</td>
                                <td>No</td>
                            </tr>
                            <tr>
                                <td>Voltage Category</td>
                                <td>Category name</td>
                                <td>Text</td>
                                <td>No</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <form id="quickImportForm" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Select Excel/CSV File</label>
                        <input type="file" class="form-control" name="excel_file" accept=".xls,.xlsx,.csv" required>
                        <div class="form-text">Supports .xls, .xlsx, and .csv files up to 10MB</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Import Mode</label>
                                <select class="form-select" name="import_mode">
                                    <option value="append">Append to existing data</option>
                                    <option value="replace">Replace existing data (delete all first)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Date Format</label>
                                <select class="form-select" name="date_format">
                                    <option value="auto">Auto-detect</option>
                                    <option value="Y-m-d H:i:s">YYYY-MM-DD HH:MM:SS</option>
                                    <option value="d/m/Y H:i:s">DD/MM/YYYY HH:MM:SS</option>
                                    <option value="m/d/Y H:i:s">MM/DD/YYYY HH:MM:SS</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="skip_duplicates" id="skipDuplicates" checked>
                        <label class="form-check-label" for="skipDuplicates">
                            Skip duplicate records (based on timestamp and values)
                        </label>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="create_categories" id="createCategories" checked>
                        <label class="form-check-label" for="createCategories">
                            Automatically create new voltage categories if they don't exist
                        </label>
                    </div>
                </form>
                
                <div id="importProgress" class="d-none">
                    <div class="progress mb-3" style="height: 25px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                             role="progressbar" style="width: 0%"></div>
                    </div>
                    <div class="text-center">
                        <span id="progressText">Processing...</span>
                        <div class="spinner-border spinner-border-sm ms-2" role="status"></div>
                    </div>
                </div>
                
                <div id="importResult" class="d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-outline-primary" onclick="window.open('import-excel.php', '_blank')">
                    <i class="fas fa-external-link-alt me-1"></i> Advanced Import
                </button>
                <button type="button" class="btn btn-success" onclick="startQuickImport()" id="importBtn">
                    <i class="fas fa-upload me-1"></i> Start Import
                </button>
            </div>
        </div>
    </div>
</div>
</body>
</html>

<?php
$stmt->close();
$mysqli->close();
?>