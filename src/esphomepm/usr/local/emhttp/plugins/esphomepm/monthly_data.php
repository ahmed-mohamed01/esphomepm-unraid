<?php
// Enable error logging
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/esphomepm_monthly_data.log'); // Dedicated log for this script

// Check if running from command line
$is_cli = (php_sapi_name() === 'cli');

// --- BEGIN NEW CLI AND GLOBAL DEFINITIONS ---
$plugin_abs_path = '/usr/local/emhttp/plugins/esphomepm'; // Absolute path for cron
$config_file = '/boot/config/plugins/esphomepm/esphomepm.cfg'; // Defined globally

if ($is_cli) {
    $options = getopt("", ["action:", "daily-update"]); // Added daily-update for direct call
    $cli_action = null;

    if (isset($options['action'])) {
        $cli_action = $options['action'];
    } elseif (isset($options['daily-update'])) { // Handle direct --daily-update call
        $cli_action = 'daily_update';
    }

    if ($cli_action) {
        error_log("CLI action called: $cli_action");
        switch ($cli_action) {
            case 'ensure_cron_exists_if_configured':
                manageCronJob(true); // True to add/ensure
                exit(0);
            case 'remove_cron_job':
                manageCronJob(false); // False to remove
                exit(0);
            case 'daily_update':
                // Ensure config dir exists, as performDailyUpdate might rely on it for loadConfig
                if (!file_exists(dirname($config_file))) {
                    // Attempt to create config directory if called directly and it's missing
                    if (!mkdir(dirname($config_file), 0755, true)) {
                        error_log("CLI daily_update: Failed to create config directory " . dirname($config_file) . ". Exiting.");
                        exit(1); // Exit if dir creation fails, as loadConfig will fail
                    }
                    error_log("CLI daily_update: Created config directory " . dirname($config_file));
                }
                performDailyUpdate();
                exit(0);
            default:
                error_log("Unknown CLI action: $cli_action");
                echo "Unknown CLI action: $cli_action\n";
                exit(1);
        }
    }
}
// --- END NEW CLI AND GLOBAL DEFINITIONS ---

// Set headers for JSON response if not in CLI mode (and no CLI action caused an exit)
if (!$is_cli) {
    header('Content-Type: application/json');
}

// Path to the data file
$data_file = '/boot/config/plugins/esphomepm/monthly_data.json';

// Path to daily data file
$daily_data_file = '/boot/config/plugins/esphomepm/daily_data.json';

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

// Function to manage cron job
function manageCronJob($add = true) {
    global $config_file, $plugin_abs_path;
    $cron_script_path = $plugin_abs_path . "/monthly_data.php";
    $cron_command_action = "--daily-update";
    $cron_schedule = "0 0 * * *"; // Daily at midnight
    $cron_identifier = "#esphomepm-daily-update"; // Unique comment

    $full_cron_command = "{$cron_schedule} php {$cron_script_path} {$cron_command_action} {$cron_identifier}";

    error_log("manageCronJob called with add=" . ($add ? 'true' : 'false'));

    // Ensure loadConfig function is available. If it's defined later, this might be an issue.
    // Consider defining loadConfig earlier or ensuring this function is called when it's available.
    if (!function_exists('loadConfig')) {
        error_log("manageCronJob: loadConfig function not found. Cannot proceed.");
        return false;
    }

    $current_crontab = shell_exec('crontab -l 2>/dev/null');
    $cron_jobs = empty($current_crontab) ? [] : explode("\n", trim($current_crontab));
    $new_cron_jobs = [];
    $job_modified = false;
    $our_job_found_and_removed = false;

    foreach ($cron_jobs as $job) {
        if (empty(trim($job))) continue;
        if (strpos($job, $cron_identifier) !== false) {
            $our_job_found_and_removed = true;
            error_log("manageCronJob: Found existing job, removing: $job");
            continue;
        }
        $new_cron_jobs[] = $job;
    }

    if ($add) {
        $config = loadConfig(); 
        if (!empty($config['device_ip'])) {
            error_log("manageCronJob: ESPHome IP is configured ('{$config['device_ip']}'). Adding cron job: $full_cron_command");
            $new_cron_jobs[] = $full_cron_command;
            $job_modified = true; 
        } else {
            error_log("manageCronJob: ESPHome IP not configured. Cron job will not be added.");
            if ($our_job_found_and_removed) { 
                $job_modified = true;
            }
        }
    } else { 
        error_log("manageCronJob: Explicitly removing cron job.");
        if ($our_job_found_and_removed) {
            $job_modified = true; 
        }
    }

    if ($job_modified) {
        // Ensure crontab content always ends with a newline for robustness
        $cron_content = empty($new_cron_jobs) ? "\n" : implode("\n", $new_cron_jobs) . "\n";
        
        $temp_crontab_file = '/tmp/esphomepm_crontab.txt';
        if (file_put_contents($temp_crontab_file, $cron_content) === false) {
            error_log("manageCronJob: Failed to write temporary crontab file to {$temp_crontab_file}.");
            return false;
        }
        
        $cmd_output = shell_exec("crontab {$temp_crontab_file} 2>&1");
        unlink($temp_crontab_file); // Clean up temporary file

        if (strpos($cmd_output, 'installing new crontab') !== false || empty($cmd_output) || $cmd_output === null) {
             error_log("manageCronJob: Crontab update likely succeeded. Command output (if any): " . trim($cmd_output) . ". Content:\n" . trim($cron_content));
        } else {
            // Some systems output errors or status messages not indicating pure success
            error_log("manageCronJob: Crontab update command executed. Output: " . trim($cmd_output) . ". Content:\n" . trim($cron_content));
        }
        return true;
    } else {
        error_log("manageCronJob: No changes made to crontab.");
        return false; 
    }
}

// Handle GET request - return the data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if this is a reset request
    if (isset($_GET['action']) && $_GET['action'] === 'reset') {
        error_log("GET request with reset action, performing data reset");
        $result = resetData(); 
        if (is_array($result) || is_object($result)) {
            echo json_encode($result);
        } else {
            echo json_encode(['success' => (bool)$result, 'message' => 'Reset processed.']);
        }
        exit; 
    }
    // --- BEGIN NEW WEB ACTION setup_device_and_cron ---
    elseif (isset($_GET['action']) && $_GET['action'] === 'setup_device_and_cron') {
        error_log("Web action called: setup_device_and_cron");
        $response = ['success' => false, 'message' => 'Initial error during setup.'];
        global $config_file; // Ensure $config_file is accessible from global scope

        $new_device_ip = isset($_GET['device_ip']) ? trim($_GET['device_ip']) : null;

        if (empty($new_device_ip) || filter_var($new_device_ip, FILTER_VALIDATE_IP) === false) {
            $response['message'] = 'Device IP not provided or invalid.';
            error_log($response['message'] . " IP received: '" . htmlspecialchars($new_device_ip) . "'");
        } else {
            // Attempt to save the new IP to the config file
            $config_content = "device_ip=" . $new_device_ip . "\n"; // Basic ini-like format
            // Add other config options here if they exist, by loading existing config first

            if (file_put_contents($config_file, $config_content) !== false) {
                chmod($config_file, 0644); // Set permissions
                error_log("Device IP '$new_device_ip' saved to $config_file");

                // Now that IP is saved, try to perform an initial update.
                // performDailyUpdate() loads the config, gets data, and updates JSON files.
                $update_result = performDailyUpdate(); // This function should return success/failure status or details

                if ($update_result && (!isset($update_result['success']) || $update_result['success'] === true)) {
                    error_log("Initial data update successful after IP save.");
                    // Now, set up the cron job as IP is saved and device is reachable
                    if (manageCronJob(true)) { // True to add/update cron job
                        $response['success'] = true;
                        $response['message'] = 'Device IP saved, data initialized, and cron job setup successfully.';
                        error_log($response['message']);
                    } else {
                        $response['message'] = 'Device IP saved, data initialized, but failed to setup cron job (manageCronJob returned false).';
                        error_log($response['message']);
                        // success remains false
                    }
                } else {
                    $error_detail = isset($update_result['error']) ? $update_result['error'] : 'performDailyUpdate returned false or error.';
                    $response['message'] = "Device IP saved, but initial data update failed: $error_detail. Cron job NOT setup.";
                    error_log($response['message']);
                    // IP is saved, but since we couldn't fetch data, we don't set up cron.
                    // The user might need to check the IP or device.
                    // We will still try to set cron on plugin start if IP is in config.
                    // However, for this specific action, we report failure to setup cron.
                }
            } else {
                $response['message'] = "Failed to save device IP to config file: $config_file";
                error_log($response['message']);
            }
        }
        echo json_encode($response);
        exit; // Exit after handling action
    }
    // --- END NEW WEB ACTION setup_device_and_cron ---
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
