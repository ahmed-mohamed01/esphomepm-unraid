<?php
// Enable error logging
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/esphomepm_monthly_data.log');

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
        try {
            $json_data = file_get_contents($data_file);
            error_log("Loading data from file: $json_data");
            
            $data = json_decode($json_data, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                // Return a new data structure if JSON is invalid
                return initializeNewData();
            }
            
            // Validate the data structure
            if (!isset($data['startDate']) || !isset($data['months']) || !is_array($data['months'])) {
                error_log("Invalid data structure, reinitializing");
                return initializeNewData();
            }
            
            return $data;
        } catch (Exception $e) {
            error_log("Exception loading data: " . $e->getMessage());
            return initializeNewData();
        }
    } else {
        error_log("Data file does not exist, creating new data");
        return initializeNewData();
    }
}

// Initialize new data structure
function initializeNewData() {
    $now = new DateTime();
    $current_month = $now->format('Y-m');
    $start_date = $now->format('Y-m-d');
    
    $initial_data = [
        'startDate' => $start_date,
        'months' => [
            $current_month => ['energy' => 0, 'cost' => 0]
        ],
        'dailyReadings' => []
    ];
    
    // Save the initial data
    saveData($initial_data);
    return $initial_data;
}

// Function to save data
function saveData($data) {
    global $data_file;
    try {
        // Ensure we have a valid data structure before saving
        if (!isset($data['startDate']) || !isset($data['months'])) {
            error_log("Invalid data structure, cannot save");
            return false;
        }
        
        // Encode with options to make it more readable
        $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON encode error: " . json_last_error_msg());
            return false;
        }
        
        error_log("Saving data: $json_data");
        file_put_contents($data_file, $json_data);
        return true;
    } catch (Exception $e) {
        error_log("Exception saving data: " . $e->getMessage());
        return false;
    }
}

// Handle GET request - return the data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(loadData());
    exit;
}

// Function to update monthly data with daily energy reading
function updateMonthlyData($daily_energy, $cost_price) {
    global $data_file;
    
    try {
        error_log("updateMonthlyData: Processing update with energy=$daily_energy, price=$cost_price");
        
        // Load existing data
        $current_data = loadData();
        
        // Get current date and month
        $now = new DateTime();
        $current_month = $now->format('Y-m');
        $today = $now->format('Y-m-d');
        
        error_log("updateMonthlyData: Current month=$current_month, Today=$today");
        
        // Make sure we have a valid data structure
        if (!isset($current_data['months'])) {
            error_log("updateMonthlyData: Months array missing, initializing data structure");
            $current_data = initializeNewData();
        }
        
        // Initialize current month if it doesn't exist
        if (!isset($current_data['months'][$current_month])) {
            error_log("updateMonthlyData: Initializing current month: $current_month");
            $current_data['months'][$current_month] = ['energy' => 0, 'cost' => 0];
        }
        
        // Initialize dailyReadings array if it doesn't exist
        if (!isset($current_data['dailyReadings'])) {
            error_log("updateMonthlyData: Initializing dailyReadings array");
            $current_data['dailyReadings'] = [];
        }
        
        // Get the previous value for today (if any)
        $prev_value = 0;
        foreach ($current_data['dailyReadings'] as $reading) {
            if (isset($reading['date']) && $reading['date'] === $today) {
                $prev_value = floatval($reading['energy']);
                break;
            }
        }
        
        error_log("updateMonthlyData: Previous value for today: $prev_value");
        
        // Calculate the difference (to avoid double-counting)
        $energy_diff = max(0, $daily_energy - $prev_value);
        error_log("updateMonthlyData: Energy difference: $energy_diff");
        
        // Update the month's total
        $current_data['months'][$current_month]['energy'] += $energy_diff;
        $current_data['months'][$current_month]['cost'] += $energy_diff * $cost_price;
        
        error_log("updateMonthlyData: Updated month totals - Energy: {$current_data['months'][$current_month]['energy']}, Cost: {$current_data['months'][$current_month]['cost']}");
        
        // Store today's reading
        $found = false;
        for ($i = 0; $i < count($current_data['dailyReadings']); $i++) {
            if (isset($current_data['dailyReadings'][$i]['date']) && $current_data['dailyReadings'][$i]['date'] === $today) {
                $current_data['dailyReadings'][$i]['energy'] = $daily_energy;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $current_data['dailyReadings'][] = [
                'date' => $today,
                'energy' => $daily_energy
            ];
        }
        
        // Save the updated data
        if (saveData($current_data)) {
            error_log("updateMonthlyData: Data saved successfully");
            return ['success' => true, 'data' => $current_data];
        } else {
            error_log("updateMonthlyData: Failed to save data");
            return ['error' => 'Failed to save data'];
        }
    } catch (Exception $e) {
        error_log("updateMonthlyData: Exception: " . $e->getMessage());
        return ['error' => 'Exception: ' . $e->getMessage()];
    }
}

// Handle POST request - update the data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        try {
            error_log("Processing daily energy update");
            $daily_energy = floatval($update_data['dailyEnergy']);
            $cost_price = floatval($update_data['costPrice']);
            
            error_log("Daily energy: $daily_energy, Cost price: $cost_price");
            
            // Get current date and month
            $now = new DateTime();
            $current_month = $now->format('Y-m');
            $today = $now->format('Y-m-d');
            
            error_log("Current month: $current_month, Today: $today");
            
            // Make sure we have a valid data structure
            if (!isset($current_data['months'])) {
                error_log("Months array missing, initializing data structure");
                $current_data = initializeNewData();
            }
            
            // Initialize current month if it doesn't exist
            if (!isset($current_data['months'][$current_month])) {
                error_log("Initializing current month: $current_month");
                $current_data['months'][$current_month] = ['energy' => 0, 'cost' => 0];
            }
            
            // Initialize dailyReadings array if it doesn't exist
            if (!isset($current_data['dailyReadings'])) {
                error_log("Initializing dailyReadings array");
                $current_data['dailyReadings'] = [];
            }
            
            // Get the previous value for today (if any)
            $prev_value = 0;
            foreach ($current_data['dailyReadings'] as $reading) {
                if (isset($reading['date']) && $reading['date'] === $today) {
                    $prev_value = floatval($reading['energy']);
                    break;
                }
            }
            
            error_log("Previous value for today: $prev_value");
            
            // Calculate the difference (to avoid double-counting)
            $energy_diff = max(0, $daily_energy - $prev_value);
            error_log("Energy difference: $energy_diff");
            
            // Update the month's total
            $current_data['months'][$current_month]['energy'] += $energy_diff;
            $current_data['months'][$current_month]['cost'] += $energy_diff * $cost_price;
            
            error_log("Updated month totals - Energy: {$current_data['months'][$current_month]['energy']}, Cost: {$current_data['months'][$current_month]['cost']}");
            
            // Store today's reading
            $found = false;
            for ($i = 0; $i < count($current_data['dailyReadings']); $i++) {
                if (isset($current_data['dailyReadings'][$i]['date']) && $current_data['dailyReadings'][$i]['date'] === $today) {
                    $current_data['dailyReadings'][$i]['energy'] = $daily_energy;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $current_data['dailyReadings'][] = [
                    'date' => $today,
                    'energy' => $daily_energy
                ];
            }
            
            // Save the updated data
            if (saveData($current_data)) {
                error_log("Data saved successfully");
                echo json_encode(['success' => true, 'data' => $current_data]);
            } else {
                error_log("Failed to save data");
                echo json_encode(['error' => 'Failed to save data']);
            }
        } catch (Exception $e) {
            error_log("Exception in daily energy update: " . $e->getMessage());
            echo json_encode(['error' => 'Exception: ' . $e->getMessage()]);
        }
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
