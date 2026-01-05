<?php
// Diagnostic script for permission issues
echo "<pre>";
echo "=== Electrical Monitoring System Diagnostic ===\n\n";

// Check if index.php exists
$index_path = __DIR__ . '/index.php';
if(file_exists($index_path)) {
    echo "✓ index.php exists\n";
    echo "  Path: $index_path\n";
} else {
    echo "✗ index.php NOT FOUND\n";
}

// Check if .htaccess exists
$htaccess_path = __DIR__ . '/.htaccess';
if(file_exists($htaccess_path)) {
    echo "✓ .htaccess exists\n";
    echo "  Path: $htaccess_path\n";
} else {
    echo "✗ .htaccess NOT FOUND\n";
}

// Check Apache modules
echo "\n=== Checking Apache Modules ===\n";
$modules = [
    'mod_rewrite' => 'Rewrite module for URL rewriting',
    'mod_headers' => 'Headers module for security headers'
];

foreach($modules as $module => $description) {
    if(function_exists('apache_get_modules')) {
        $loaded_modules = apache_get_modules();
        $loaded = in_array($module, $loaded_modules);
        echo ($loaded ? "✓" : "✗") . " $module: $description\n";
    } else {
        echo "? $module: Cannot check (function not available)\n";
    }
}

// Check PHP version
echo "\n=== PHP Information ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";

// Check directory permissions
echo "\n=== Directory Permissions ===\n";
$dirs_to_check = [
    '.',
    'config',
    'reports',
    'error'
];

foreach($dirs_to_check as $dir) {
    $path = __DIR__ . '/' . $dir;
    if(is_dir($path)) {
        $writable = is_writable($path);
        echo ($writable ? "✓" : "✗") . " $dir is " . ($writable ? "writable" : "NOT writable") . "\n";
    } else {
        echo "? $dir directory does not exist\n";
    }
}

// Check important files
echo "\n=== Important Files ===\n";
$important_files = [
    'config/database.php',
    'login.php',
    'dashboard.php',
    'reports.php'
];

foreach($important_files as $file) {
    $path = __DIR__ . '/' . $file;
    if(file_exists($path)) {
        $readable = is_readable($path);
        echo ($readable ? "✓" : "✗") . " $file exists and is " . ($readable ? "readable" : "NOT readable") . "\n";
    } else {
        echo "✗ $file NOT FOUND\n";
    }
}

echo "\n=== Server Variables ===\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Not set') . "\n";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'Not set') . "\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'Not set') . "\n";

// Test database connection
echo "\n=== Database Test ===\n";
try {
    require_once 'config/database.php';
    if(isset($mysqli) && $mysqli->ping()) {
        echo "✓ Database connection successful\n";
        
        // Check if tables exist
        $tables = ['users', 'electrical_data', 'reports'];
        foreach($tables as $table) {
            $result = $mysqli->query("SHOW TABLES LIKE '$table'");
            echo ($result->num_rows > 0 ? "✓" : "✗") . " Table '$table' exists\n";
        }
    } else {
        echo "✗ Database connection failed\n";
    }
} catch(Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
}

echo "\n=== Recommendations ===\n";
echo "1. Ensure index.php exists in root directory\n";
echo "2. Ensure .htaccess file is present\n";
echo "3. Check Apache httpd.conf for AllowOverride All\n";
echo "4. Restart Apache after configuration changes\n";
echo "5. Access via: http://localhost/electrical_monitoring1/\n";

echo "</pre>";
?>