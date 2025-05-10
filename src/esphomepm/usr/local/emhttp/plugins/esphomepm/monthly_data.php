<?php
// Enable error logging
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/esphomepm_monthly_data.log');

// Check if running from command line
$is_cli = (php_sapi_name() === 'cli');

// Set headers for JSON response if not in CLI mode
if (!$is_cli) {
    header('Content-Type: application/json');
}

// Path to the data file
$data_file = '/boot/config/plugins/esphomepm/monthly_data.json';

// Path to daily data file
$daily_data_file = '/boot/config/plugins/esphomepm/daily_data.json';

// Path to config file
$config_file = '/boot/config/plugins/esphomepm/esphomepm.cfg';

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

// Function to load daily data
function loadDailyData() {
    global $daily_data_file;
    
    if (file_exists($daily_data_file)) {
        try {
            $json_data = file_get_contents($daily_data_file);
            error_log("Loading daily data from file: $json_data");
            
            $data = json_decode($json_data, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error for daily data: " . json_last_error_msg());
                // Return a new data structure if JSON is invalid
                return initializeNewDailyData();
            }
            
            // Validate the data structure
            if (!isset($data['days']) || !is_array($data['days'])) {
                error_log("Invalid daily data structure, reinitializing");
                return initializeNewDailyData();
            }
            
            return $data;
        } catch (Exception $e) {
            error_log("Exception loading daily data: " . $e->getMessage());
            return initializeNewDailyData();
        }
    } else {
        error_log("Daily data file does not exist, creating new data");
        return initializeNewDailyData();
    }
}

// Initialize new data structure
function initializeNewData() {
    $now = new DateTime();
    $current_month = $now->format('Y-m');
    $today = $now->format('Y-m-d');
    
    $data = [
        'startDate' => $today,
        'months' => [
            $current_month => [
                'energy' => 0,
                'cost' => 0
            ]
        ],
        'lastUpdate' => $now->format('Y-m-d H:i:s')
    ];
    
    error_log("Initialized new data structure");
    return $data;
}

// Initialize new daily data structure
function initializeNewDailyData() {
    $now = new DateTime();
    $today = $now->format('Y-m-d');
    
    $data = [
        'days' => [
            $today => [
                'energy' => 0,
                'hourlyReadings' => [],
                'lastUpdate' => $now->format('Y-m-d H:i:s')
            ]
        ],
        'currentDay' => $today,
        'lastUpdate' => $now->format('Y-m-d H:i:s')
    ];
    
    error_log("Initialized new daily data structure");
    return $data;
}

// Function to save data
function saveData($data) {
    global $data_file;
    
    try {
        // Ensure the directory exists
        $dir = dirname($data_file);
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create directory: $dir");
                return false;
            }
        }
        
        // Add timestamp for debugging
        $data['lastUpdate'] = (new DateTime())->format('Y-m-d H:i:s');
        
        // Convert to JSON
        $json_data = json_encode($data, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON encode error: " . json_last_error_msg());
            return false;
        }
        
        // Write to file
        if (file_put_contents($data_file, $json_data, LOCK_EX) === false) {
            error_log("Failed to write to file: $data_file");
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Exception saving data: " . $e->getMessage());
        return false;
    }
}

// Function to save daily data
function saveDailyData($data) {
    global $daily_data_file;
    
    try {
        // Ensure the directory exists
        $dir = dirname($daily_data_file);
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create directory: $dir");
                return false;
            }
        }
        
        // Add timestamp for debugging
        $data['lastUpdate'] = (new DateTime())->format('Y-m-d H:i:s');
        
        // Convert to JSON
        $json_data = json_encode($data, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON encode error for daily data: " . json_last_error_msg());
            return false;
        }
        
        // Write to file
        if (file_put_contents($daily_data_file, $json_data, LOCK_EX) === false) {
            error_log("Failed to write to daily data file: $daily_data_file");
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Exception saving daily data: " . $e->getMessage());
        return false;
    }
}

// Function to get current energy data from ESPHome device
function getCurrentEnergyData($device_ip) {
    if (empty($device_ip)) {
        error_log("Device IP is empty, cannot fetch data");
        return false;
    }
    
    try {
        // Construct the API URL
        $url = "http://$device_ip/api/esphome/state";
        
        // Set up context with timeout
        $context = stream_context_create([
            'http' => [
                'timeout' => 5, // 5 seconds timeout
                'ignore_errors' => true
            ]
        ]);
        
        // Fetch data from the ESPHome API
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            error_log("Failed to connect to ESPHome device at $device_ip");
            return false;
        }
        
        // Parse the JSON response
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            return false;
        }
        
        // Extract the energy data
        $energy_data = [
            'power' => 0,
            'total_energy' => 0
        ];
        
        // Look for power and energy sensors in the data
        foreach ($data as $key => $value) {
            if (strpos(strtolower($key), 'power') !== false && is_numeric($value)) {
                $energy_data['power'] = floatval($value);
            }
            if ((strpos(strtolower($key), 'energy') !== false || strpos(strtolower($key), 'total') !== false) && is_numeric($value)) {
                $energy_data['total_energy'] = floatval($value);
            }
        }
        
        return $energy_data;
    } catch (Exception $e) {
        error_log("Exception getting energy data: " . $e->getMessage());
        return false;
    }
}

// Function to load configuration
function loadConfig() {
    global $config_file;
    
    if (file_exists($config_file)) {
        try {
            $config = parse_ini_file($config_file);
            if ($config === false) {
                error_log("Failed to parse config file");
                return [
                    'DEVICE_IP' => '',
                    'COSTS_PRICE' => '0.27',
                    'COSTS_UNIT' => 'GBP'
                ];
            }
            return $config;
        } catch (Exception $e) {
            error_log("Exception loading config: " . $e->getMessage());
            return [
                'DEVICE_IP' => '',
                'COSTS_PRICE' => '0.27',
                'COSTS_UNIT' => 'GBP'
            ];
        }
    } else {
        error_log("Config file does not exist");
        return [
            'DEVICE_IP' => '',
            'COSTS_PRICE' => '0.27',
            'COSTS_UNIT' => 'GBP'
        ];
    }
}

// Function to check for existing data files
function checkForExistingData() {
    global $data_file, $daily_data_file;
    
    $result = [
        'monthly_exists' => file_exists($data_file),
        'daily_exists' => file_exists($daily_data_file)
    ];
    
    error_log("Checking for existing data: Monthly=" . ($result['monthly_exists'] ? 'Yes' : 'No') . 
              ", Daily=" . ($result['daily_exists'] ? 'Yes' : 'No'));
    
    return $result;
}

// Function to perform daily update of energy data
function performDailyUpdate() {
    error_log("Performing daily update at " . date('Y-m-d H:i:s'));
    
    // Check for existing data
    $existing_data = checkForExistingData();
    
    // Load configuration
    $config = loadConfig();
    $device_ip = isset($config['DEVICE_IP']) ? $config['DEVICE_IP'] : '';
    $cost_price = isset($config['COSTS_PRICE']) ? floatval($config['COSTS_PRICE']) : 0.27;
    
    if (empty($device_ip)) {
        error_log("Device IP is not configured, cannot collect data");
        return ['error' => 'Device IP not configured', 'success' => false];
    }
    
    // Get current energy data
    $energy_data = getCurrentEnergyData($device_ip);
    
    if ($energy_data === false) {
        error_log("Failed to get energy data from device");
        return ['error' => 'Failed to get energy data', 'success' => false];
    }
    
    error_log("Successfully retrieved energy data: power={$energy_data['power']}W, energy={$energy_data['total_energy']}kWh");
    
    // Update energy data with force flag to ensure today's data is included
    return updateEnergyData($energy_data['total_energy'], $cost_price, $energy_data['power'], true);
}

// Function to update hourly energy reading and calculate daily/monthly totals
function updateEnergyData($daily_energy, $cost_price, $power = 0, $force_update = false) {
    try {
        error_log("updateEnergyData: Processing update with energy=$daily_energy, price=$cost_price, power=$power");
        
        // Get current date and time
        $now = new DateTime();
        $current_month = $now->format('Y-m');
        $today = $now->format('Y-m-d');
        $current_hour = $now->format('H:i');
        
        error_log("updateEnergyData: Current month=$current_month, Today=$today, Hour=$current_hour");
        
        // Load daily data
        $daily_data = loadDailyData();
        
        // Check if we need to start a new day
        if ($daily_data['currentDay'] !== $today) {
            error_log("updateEnergyData: Starting a new day. Previous day: {$daily_data['currentDay']}, New day: $today");
            
            // Initialize the new day
            $daily_data['days'][$today] = [
                'energy' => 0,
                'hourlyReadings' => [],
                'lastUpdate' => $now->format('Y-m-d H:i:s')
            ];
            
            $daily_data['currentDay'] = $today;
        }
        
        // Get the current day's data
        $current_day_data = &$daily_data['days'][$today];
        
        // Add hourly reading
        $current_day_data['hourlyReadings'][] = [
            'time' => $current_hour,
            'energy' => $daily_energy,
            'power' => $power
        ];
        
        // Update the day's total energy
        $current_day_data['energy'] = $daily_energy;
        $current_day_data['lastUpdate'] = $now->format('Y-m-d H:i:s');
        
        // Save daily data
        saveDailyData($daily_data);
        
        // Now update the monthly data
        $monthly_data = loadData();
        
        // Make sure we have a valid data structure
        if (!isset($monthly_data['months'])) {
            error_log("updateEnergyData: Months array missing, initializing data structure");
            $monthly_data = initializeNewData();
        }
        
        // Initialize current month if it doesn't exist
        if (!isset($monthly_data['months'][$current_month])) {
            error_log("updateEnergyData: Initializing current month: $current_month");
            $monthly_data['months'][$current_month] = ['energy' => 0, 'cost' => 0];
        }
        
        // Calculate monthly total by summing all days in this month
        $monthly_total_energy = 0;
        $days_in_month = [];
        
        // Get all days that belong to the current month
        foreach ($daily_data['days'] as $date => $day_data) {
            if (strpos($date, $current_month) === 0) { // If date starts with current month (YYYY-MM)
                $days_in_month[$date] = $day_data['energy'];
                $monthly_total_energy += $day_data['energy'];
            }
        }
        
        error_log("updateEnergyData: Calculated monthly total energy: $monthly_total_energy kWh from " . count($days_in_month) . " days");
        
        // Make sure today's data is included in the monthly total
        if ((!isset($days_in_month[$today]) || $force_update) && $daily_energy > 0) {
            error_log("updateEnergyData: Today's data not in monthly calculation or force update, adding it now");
            $days_in_month[$today] = $daily_energy;
            $monthly_total_energy += $daily_energy;
        }
        
        // Update the month's data
        $monthly_data['months'][$current_month]['energy'] = $monthly_total_energy;
        $monthly_data['months'][$current_month]['cost'] = $monthly_total_energy * $cost_price;
        $monthly_data['months'][$current_month]['days'] = $days_in_month;
        
        // Save the updated monthly data
        if (saveData($monthly_data)) {
            error_log("updateEnergyData: Monthly data saved successfully");
            return ['success' => true, 'data' => $monthly_data];
        } else {
            error_log("updateEnergyData: Failed to save monthly data");
            return ['error' => 'Failed to save monthly data', 'success' => false];
        }
    } catch (Exception $e) {
        error_log("updateEnergyData: Exception: " . $e->getMessage());
        return ['error' => 'Exception: ' . $e->getMessage(), 'success' => false];
    }
}

// Handle GET request - return the data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if we need to ensure today's data is included
    if (isset($_GET['ensure_today']) && $_GET['ensure_today'] === '1') {
        error_log("GET request with ensure_today flag, performing update");
        performDailyUpdate();
    }
    
    echo json_encode(loadData());
    exit;
}

// Handle POST request - update the data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the JSON data from the request
    $input_data = file_get_contents('php://input');
    error_log("Received POST data: $input_data");
    
    // Check if input data is empty
    if (empty($input_data)) {
        header('Content-Type: application/json');
        $error_response = ['error' => 'No data received', 'success' => false];
        echo json_encode($error_response);
        error_log("Sending error response: " . json_encode($error_response));
        exit;
    }
    
    // Parse the JSON data
    $update_data = json_decode($input_data, true);
    
    // Check if JSON is valid
    if (json_last_error() !== JSON_ERROR_NONE) {
        header('Content-Type: application/json');
        $error_response = ['error' => 'Invalid JSON: ' . json_last_error_msg(), 'success' => false];
        echo json_encode($error_response);
        error_log("Sending error response: " . json_encode($error_response));
        exit;
    }
    
    // Check if the required fields are present
    if (!isset($update_data['daily_energy']) || !is_numeric($update_data['daily_energy'])) {
        header('Content-Type: application/json');
        $error_response = ['error' => 'Missing or invalid daily_energy field', 'success' => false];
        echo json_encode($error_response);
        error_log("Sending error response: " . json_encode($error_response));
        exit;
    }
        
        // Get the daily energy value
        $daily_energy = floatval($update_data['daily_energy']);
        
        // Get the power value if available
        $power = isset($update_data['power']) && is_numeric($update_data['power']) ? floatval($update_data['power']) : 0;
        
        // Get the cost price from the config file or use a default value
        $cost_price = 0.27; // Default value
        $config_file = '/boot/config/plugins/esphomepm/esphomepm.cfg';
        
        if (file_exists($config_file)) {
            try {
                $config = parse_ini_file($config_file);
                if ($config !== false && isset($config['COSTS_PRICE']) && is_numeric($config['COSTS_PRICE'])) {
                    $cost_price = floatval($config['COSTS_PRICE']);
                }
            } catch (Exception $e) {
                error_log("Exception loading config: " . $e->getMessage());
            }
        }
        
        // Update the energy data (hourly, daily, and monthly)
        $result = updateEnergyData($daily_energy, $cost_price, $power);
        
        // Return the result
        echo json_encode($result);
        exit;
    }
    
    // Handle full data replacement (if needed)
    if (isset($update_data['fullData'])) {
        saveData($update_data['fullData']);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    header('Content-Type: application/json');
    $error_response = ['error' => 'Invalid update data'];
    echo json_encode($error_response);
    error_log("Sending error response: " . json_encode($error_response));
    exit;
}

// If we reach here, it's an unsupported method
if (!$is_cli) {
    header('Content-Type: application/json');
    $error_response = ['error' => 'Unsupported request method'];
    echo json_encode($error_response);
    error_log("Sending error response: " . json_encode($error_response));
    exit;
}

// Command line execution handling
if ($is_cli) {
    // Check command line arguments
    $options = getopt('', ['daily-update']);
    
    // Check if daily update is requested
    if (isset($options['daily-update'])) {
        error_log("Running daily update from command line");
        $result = performDailyUpdate();
        
        if (isset($result['success']) && $result['success']) {
            error_log("Daily update completed successfully");
            exit(0);
        } else {
            error_log("Daily update failed: " . ($result['error'] ?? 'Unknown error'));
            exit(1);
        }
    } else {
        error_log("No valid command line option specified");
        echo "Usage: php monthly_data.php --daily-update\n";
        exit(1);
    }
}
?>
