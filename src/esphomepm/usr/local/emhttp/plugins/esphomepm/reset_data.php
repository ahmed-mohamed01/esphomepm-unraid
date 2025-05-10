<?php
// Enable error logging
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/esphomepm_reset_data.log');

// Set headers for JSON response
header('Content-Type: application/json');

// Path to the data files
$monthly_data_file = '/boot/config/plugins/esphomepm/monthly_data.json';
$daily_data_file = '/boot/config/plugins/esphomepm/daily_data.json';

// Function to initialize new monthly data structure
function initializeNewData() {
    $currentMonth = date('Y-m');
    $startDate = date('Y-m-d');
    
    $data = [
        'startDate' => $startDate,
        'months' => []
    ];
    
    // Initialize the current month with zeros
    $data['months'][$currentMonth] = [
        'energy' => 0,
        'cost' => 0
    ];
    
    return $data;
}

// Function to initialize new daily data structure
function initializeNewDailyData() {
    $today = date('Y-m-d');
    
    $data = [
        'date' => $today,
        'total_energy' => 0,
        'total_cost' => 0,
        'hourly_readings' => []
    ];
    
    return $data;
}

// Function to save data
function saveData($data) {
    global $monthly_data_file;
    
    try {
        // Create directory if it doesn't exist
        if (!file_exists(dirname($monthly_data_file))) {
            mkdir(dirname($monthly_data_file), 0755, true);
        }
        
        // Save the data to file
        $result = file_put_contents($monthly_data_file, json_encode($data, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            error_log("Failed to save data to file: $monthly_data_file");
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Exception while saving data: " . $e->getMessage());
        return false;
    }
}

// Function to save daily data
function saveDailyData($data) {
    global $daily_data_file;
    
    try {
        // Create directory if it doesn't exist
        if (!file_exists(dirname($daily_data_file))) {
            mkdir(dirname($daily_data_file), 0755, true);
        }
        
        // Save the data to file
        $result = file_put_contents($daily_data_file, json_encode($data, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            error_log("Failed to save daily data to file: $daily_data_file");
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Exception while saving daily data: " . $e->getMessage());
        return false;
    }
}

// Reset the data files
try {
    // Initialize new data structures
    $new_monthly_data = initializeNewData();
    $new_daily_data = initializeNewDailyData();
    
    // Save the new data
    $monthly_result = saveData($new_monthly_data);
    $daily_result = saveDailyData($new_daily_data);
    
    if ($monthly_result && $daily_result) {
        $response = [
            'success' => true,
            'message' => 'Monthly and daily data have been reset successfully'
        ];
        echo json_encode($response);
        exit;
    } else {
        throw new Exception("Failed to save new data files");
    }
} catch (Exception $e) {
    $error_response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
    echo json_encode($error_response);
    error_log("Error resetting data: " . $e->getMessage());
    exit;
}
?>
