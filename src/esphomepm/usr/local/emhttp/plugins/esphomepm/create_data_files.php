<?php
// Enable error logging to a file we can check
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/esphomepm_create_files.log');

// Define the paths
$data_file = '/boot/config/plugins/esphomepm/monthly_data.json';
$daily_data_file = '/boot/config/plugins/esphomepm/daily_data.json';
$config_file = '/boot/config/plugins/esphomepm/esphomepm.cfg';

echo "Starting diagnostic script...\n";
error_log("Starting diagnostic script");

// Check if directory exists
if (!file_exists(dirname($data_file))) {
    echo "Creating directory: " . dirname($data_file) . "\n";
    error_log("Creating directory: " . dirname($data_file));
    $result = mkdir(dirname($data_file), 0755, true);
    if (!$result) {
        echo "Failed to create directory: " . dirname($data_file) . "\n";
        error_log("Failed to create directory: " . dirname($data_file));
    } else {
        echo "Successfully created directory: " . dirname($data_file) . "\n";
        error_log("Successfully created directory: " . dirname($data_file));
    }
} else {
    echo "Directory already exists: " . dirname($data_file) . "\n";
    error_log("Directory already exists: " . dirname($data_file));
    
    // Check directory permissions
    $perms = substr(sprintf('%o', fileperms(dirname($data_file))), -4);
    echo "Directory permissions: " . $perms . "\n";
    error_log("Directory permissions: " . $perms);
    
    if (!is_writable(dirname($data_file))) {
        echo "Directory is not writable, attempting to fix permissions\n";
        error_log("Directory is not writable, attempting to fix permissions");
        chmod(dirname($data_file), 0755);
    }
}

// Create a simple monthly data structure
$today = date('Y-m-d');
$currentMonth = date('Y-m');

$monthly_data = [
    'startDate' => $today,
    'months' => [
        $currentMonth => [
            'energy' => 0,
            'cost' => 0
        ]
    ]
];

// Create a simple daily data structure
$daily_data = [
    'date' => $today,
    'total_energy' => 0,
    'total_cost' => 0,
    'hourly_readings' => []
];

// Try to save the monthly data
echo "Attempting to save monthly data file...\n";
error_log("Attempting to save monthly data file");
$monthly_result = file_put_contents($data_file, json_encode($monthly_data, JSON_PRETTY_PRINT));
if ($monthly_result === false) {
    echo "Failed to save monthly data file: " . $data_file . "\n";
    error_log("Failed to save monthly data file: " . $data_file);
} else {
    echo "Successfully saved monthly data file: " . $data_file . "\n";
    error_log("Successfully saved monthly data file: " . $data_file);
}

// Try to save the daily data
echo "Attempting to save daily data file...\n";
error_log("Attempting to save daily data file");
$daily_result = file_put_contents($daily_data_file, json_encode($daily_data, JSON_PRETTY_PRINT));
if ($daily_result === false) {
    echo "Failed to save daily data file: " . $daily_data_file . "\n";
    error_log("Failed to save daily data file: " . $daily_data_file);
} else {
    echo "Successfully saved daily data file: " . $daily_data_file . "\n";
    error_log("Successfully saved daily data file: " . $daily_data_file);
}

// Check if the files exist now
echo "Monthly data file exists: " . (file_exists($data_file) ? "Yes" : "No") . "\n";
echo "Daily data file exists: " . (file_exists($daily_data_file) ? "Yes" : "No") . "\n";

echo "Diagnostic script completed. Check /tmp/esphomepm_create_files.log for details.\n";
?>
