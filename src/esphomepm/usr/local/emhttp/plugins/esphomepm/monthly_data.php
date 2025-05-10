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

// Ensure the directory exists with proper permissions
if (!file_exists(dirname($data_file))) {
    error_log("Creating directory: " . dirname($data_file));
    $result = mkdir(dirname($data_file), 0755, true);
    if (!$result) {
        error_log("Failed to create directory: " . dirname($data_file));
    } else {
        error_log("Successfully created directory: " . dirname($data_file));
    }
}

// Verify directory permissions
if (file_exists(dirname($data_file))) {
    $perms = substr(sprintf('%o', fileperms(dirname($data_file))), -4);
    error_log("Directory permissions: " . $perms . " for " . dirname($data_file));
    
    // Try to ensure the directory is writable
    if (!is_writable(dirname($data_file))) {
        error_log("Directory is not writable, attempting to fix: " . dirname($data_file));
        chmod(dirname($data_file), 0755);
    }
}

// Function to load data
function loadData() {
    global $data_file;
    
    // Check if data file exists
    if (file_exists($data_file)) {
        error_log("Loading data from existing file: $data_file");
        $json_data = file_get_contents($data_file);
        if ($json_data === false) {
            error_log("Failed to read data file: $data_file");
            $data = initializeNewData();
            saveData($data); // Save the initialized data
            return $data;
        }
        
        $data = json_decode($json_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Failed to parse JSON data: " . json_last_error_msg());
            $data = initializeNewData();
            saveData($data); // Save the initialized data
            return $data;
        }
        
        return $data;
    } else {
        error_log("Data file does not exist: $data_file, initializing new data");
        $data = initializeNewData();
        $result = saveData($data); // Save the initialized data
        if (!$result) {
            error_log("Failed to save initialized data to: $data_file");
        } else {
            error_log("Successfully created and saved new data file: $data_file");
        }
        return $data;
    }
}

// Function to load daily data
function loadDailyData() {
    global $daily_data_file;
    
    // Check if daily data file exists
    if (file_exists($daily_data_file)) {
        error_log("Loading daily data from existing file: $daily_data_file");
        $json_data = file_get_contents($daily_data_file);
        if ($json_data === false) {
            error_log("Failed to read daily data file: $daily_data_file");
            $data = initializeNewDailyData();
            saveDailyData($data); // Save the initialized data
            return $data;
        }
        
        $data = json_decode($json_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Failed to parse daily JSON data: " . json_last_error_msg());
            $data = initializeNewDailyData();
            saveDailyData($data); // Save the initialized data
            return $data;
        }
        
        return $data;
    } else {
        error_log("Daily data file does not exist: $daily_data_file, initializing new data");
        $data = initializeNewDailyData();
        $result = saveDailyData($data); // Save the initialized data
        if (!$result) {
            error_log("Failed to save initialized daily data to: $daily_data_file");
        } else {
            error_log("Successfully created and saved new daily data file: $daily_data_file");
        }
        return $data;
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
        
        // Save the data to file
        $result = file_put_contents($data_file, $json_data);
        
        if ($result === false) {
            error_log("Failed to save data to file: $data_file");
            return false;
        }
        
        // Ensure file has proper permissions
        chmod($data_file, 0644);
        error_log("Set permissions on file: $data_file");
        
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
            error_log("Creating directory: " . dirname($daily_data_file));
            $result = mkdir(dirname($daily_data_file), 0755, true);
            if (!$result) {
                error_log("Failed to create directory: " . dirname($daily_data_file));
                return false;
            }
            
            // Ensure directory has proper permissions
            chmod(dirname($daily_data_file), 0755);
            error_log("Set permissions on directory: " . dirname($daily_data_file));
        }
        
        // Add timestamp for debugging
        $data['lastUpdate'] = (new DateTime())->format('Y-m-d H:i:s');
        
        // Convert to JSON
        $json_data = json_encode($data, JSON_PRETTY_PRINT);
        
        // Save the data to file
        $result = file_put_contents($daily_data_file, $json_data);
        
        if ($result === false) {
            error_log("Failed to save daily data to file: $daily_data_file");
            return false;
        }
        
        // Ensure file has proper permissions
        chmod($daily_data_file, 0644);
        error_log("Set permissions on file: $daily_data_file");
        
        return true;
    } catch (Exception $e) {
        error_log("Exception while saving daily data: " . $e->getMessage());
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

// Function to reset data while preserving current day's energy
function resetData() {
    error_log("Resetting data at " . date('Y-m-d H:i:s'));
    
    try {
        // Load configuration
        $config = loadConfig();
        $device_ip = isset($config['DEVICE_IP']) ? $config['DEVICE_IP'] : '';
        $cost_price = isset($config['COSTS_PRICE']) ? floatval($config['COSTS_PRICE']) : 0.27;
        
        if (empty($device_ip)) {
            error_log("Device IP is not configured, cannot reset data properly");
            return ['error' => 'Device IP not configured', 'success' => false];
        }
        
        // Get current energy data
        $energy_data = getCurrentEnergyData($device_ip);
        
        if ($energy_data === false) {
            error_log("Failed to get energy data from device during reset");
            return ['error' => 'Failed to get energy data', 'success' => false];
        }
        
        // Extract current values
        $daily_energy = floatval($energy_data['total_energy']);
        $power = floatval($energy_data['power']);
        $daily_cost = $daily_energy * $cost_price;
        
        // Initialize new monthly data structure
        $today = date('Y-m-d');
        $currentMonth = date('Y-m');
        
        // Create new monthly data structure
        $monthly_data = [
            'startDate' => $today,
            'months' => []
        ];
        
        // Initialize the current month with current day's values
        $monthly_data['months'][$currentMonth] = [
            'energy' => $daily_energy,
            'cost' => $daily_cost
        ];
        
        // Create new daily data structure with current values
        $daily_data = [
            'date' => $today,
            'total_energy' => $daily_energy,
            'total_cost' => $daily_cost,
            'hourly_readings' => [
                [
                    'time' => date('H:i'),
                    'energy' => $daily_energy,
                    'power' => $power
                ]
            ]
        ];
        
        // Save the data
        $monthly_result = saveData($monthly_data);
        $daily_result = saveDailyData($daily_data);
        
        if (!$monthly_result || !$daily_result) {
            error_log("Failed to save data during reset");
            return ['error' => 'Failed to save reset data', 'success' => false];
        }
        
        error_log("Data reset successfully with current day's energy: $daily_energy kWh, cost: $daily_cost");
        return [
            'success' => true, 
            'message' => 'Data reset successfully',
            'current_energy' => $daily_energy,
            'current_cost' => $daily_cost
        ];
    } catch (Exception $e) {
        error_log("Exception in resetData: " . $e->getMessage());
        return ['error' => $e->getMessage(), 'success' => false];
    }
}

// Handle GET request - return the data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if this is a reset request
    if (isset($_GET['action']) && $_GET['action'] === 'reset') {
        error_log("GET request with reset action, performing data reset");
        $result = resetData();
        echo json_encode($result);
        exit;
    }
    
    // Check if this is an initialization request
    if (isset($_GET['action']) && $_GET['action'] === 'init') {
        error_log("GET request with init action, initializing data files");
        
        // Ensure both data files exist
        $monthly_data = loadData();
        $daily_data = loadDailyData();
        
        // Try to update with current values if possible
        $update_result = performDailyUpdate();
        
        $result = [
            'success' => true,
            'monthly_data_exists' => file_exists($data_file),
            'daily_data_exists' => file_exists($daily_data_file),
            'update_result' => $update_result
        ];
        
        echo json_encode($result);
        exit;
    }
    
    // Check if we need to ensure today's data is included
    if (isset($_GET['ensure_today']) && $_GET['ensure_today'] === '1') {
        error_log("GET request with ensure_today flag, performing update");
        performDailyUpdate();
    }
    
    // Load data and ensure it exists
    $data = loadData();
    
    // Also ensure daily data exists
    loadDailyData();
    
    echo json_encode($data);
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
