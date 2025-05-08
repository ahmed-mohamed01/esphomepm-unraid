<?php
// Include Unraid utility functions for CSRF protection
require_once '/usr/local/emhttp/webGui/include/Helpers.php';

// Set headers for JSON response
header('Content-Type: application/json');

// Path to the data file
$data_file = '/boot/config/plugins/esphomepm/monthly_data.json';

// Ensure the directory exists
if (!file_exists(dirname($data_file))) {
    mkdir(dirname($data_file), 0755, true);
}

// Function to load data
function loadData() {
    global $data_file;
    
    if (file_exists($data_file)) {
        $json_data = file_get_contents($data_file);
        return json_decode($json_data, true);
    } else {
        // Initialize with current date if no data exists
        $now = new DateTime();
        $current_month = $now->format('Y-m');
        $start_date = $now->format('Y-m-d');
        
        $initial_data = [
            'startDate' => $start_date,
            'months' => [
                $current_month => ['energy' => 0, 'cost' => 0]
            ]
        ];
        
        // Save the initial data
        file_put_contents($data_file, json_encode($initial_data));
        return $initial_data;
    }
}

// Function to save data
function saveData($data) {
    global $data_file;
    file_put_contents($data_file, json_encode($data));
    return true;
}

// Handle GET request - return the data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(loadData());
    exit;
}

// Handle POST request - update the data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check for CSRF token
    if (!isset($_POST['csrf_token']) && !isset($_REQUEST['csrf_token'])) {
        // For API calls, check for token in headers
        $headers = getallheaders();
        $token = isset($headers['X-CSRF-TOKEN']) ? $headers['X-CSRF-TOKEN'] : '';
        
        // If still no token, try to extract from JSON data
        if (empty($token)) {
            $input_data = file_get_contents('php://input');
            $update_data = json_decode($input_data, true);
            $token = isset($update_data['csrf_token']) ? $update_data['csrf_token'] : '';
        }
    } else {
        // Get token from POST or REQUEST
        $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : $_REQUEST['csrf_token'];
    }
    
    // Validate CSRF token
    if (empty($token) || !csrf_validate($token)) {
        error_log('CSRF token validation failed in monthly_data.php');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    
    // Get the JSON data from the request
    $input_data = file_get_contents('php://input');
    $update_data = json_decode($input_data, true);
    
    if (!$update_data) {
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }
    
    // Load existing data
    $current_data = loadData();
    
    // Handle daily energy update
    if (isset($update_data['dailyEnergy']) && isset($update_data['costPrice'])) {
        $daily_energy = floatval($update_data['dailyEnergy']);
        $cost_price = floatval($update_data['costPrice']);
        
        // Get current date and month
        $now = new DateTime();
        $current_month = $now->format('Y-m');
        $today = $now->format('Y-m-d');
        
        // Initialize current month if it doesn't exist
        if (!isset($current_data['months'][$current_month])) {
            $current_data['months'][$current_month] = ['energy' => 0, 'cost' => 0];
        }
        
        // Get the previous value for today (if any)
        $prev_value = isset($current_data[$today]) ? floatval($current_data[$today]) : 0;
        
        // Calculate the difference (to avoid double-counting)
        $energy_diff = max(0, $daily_energy - $prev_value);
        
        // Update the month's total
        $current_data['months'][$current_month]['energy'] += $energy_diff;
        $current_data['months'][$current_month]['cost'] += $energy_diff * $cost_price;
        
        // Store today's value
        $current_data[$today] = $daily_energy;
        
        // Save the updated data
        saveData($current_data);
        
        echo json_encode(['success' => true, 'data' => $current_data]);
        exit;
    }
    
    // Handle full data replacement (if needed)
    if (isset($update_data['fullData'])) {
        saveData($update_data['fullData']);
        echo json_encode(['success' => true]);
        exit;
    }
    
    echo json_encode(['error' => 'Invalid update data']);
    exit;
}

// If we reach here, it's an unsupported method
echo json_encode(['error' => 'Unsupported request method']);
?>
