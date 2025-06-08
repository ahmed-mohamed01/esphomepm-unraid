<?php
/**
 * ESPHomePM Plugin - Core Utilities
 *
 * This file contains essential, general-purpose utilities used throughout the plugin.
 */

// Define common constants
define('ESPHOMPM_PLUGIN_NAME', 'esphomepm');
define('ESPHOMPM_CONFIG_FILE', '/boot/config/plugins/' . ESPHOMPM_PLUGIN_NAME . '/' . ESPHOMPM_PLUGIN_NAME . '.cfg');
define('ESPHOMPM_DATA_FILE', '/boot/config/plugins/' . ESPHOMPM_PLUGIN_NAME . '/esphomepm_data.json');
define('ESPHOMPM_LOG_FILE', '/boot/config/plugins/' . ESPHOMPM_PLUGIN_NAME . '/esphomepm_cron.log');
define('ESPHOMPM_DATA_FORMAT_VERSION', '1.0');
define('ESPHOMPM_MAX_HISTORICAL_MONTHS', 13); // Keep current year + last year approx

/**
 * Standardized error logging for ESPHomePM plugin
 * 
 * @param string $message The message to log
 * @param string $level The log level (INFO, WARNING, ERROR, CRITICAL)
 * @param string $component The component generating the log
 */
function esphomepm_log_error($message, $level = 'ERROR', $component = 'general') {
    $timestamp = date('Y-m-d H:i:s');
    error_log("ESPHomePM [$level] [$component]: $message");
}

/**
 * Set up error logging for ESPHomePM plugin
 * 
 * @param string $component Name of the component for the log file
 * @param bool $display_errors Whether to display errors (default: false)
 */
function esphomepm_setup_error_logging($component = 'general', $display_errors = false) {
    ini_set('display_errors', $display_errors ? 1 : 0);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    ini_set('error_log', '/boot/config/plugins/' . ESPHOMPM_PLUGIN_NAME . "/esphomepm_{$component}_error.log");
}

/**
 * Load plugin configuration from the config file
 * 
 * @return array Configuration settings
 */
function esphomepm_load_config() {
    $config = [];
    if (file_exists(ESPHOMPM_CONFIG_FILE)) {
        $raw_cfg = parse_ini_file(ESPHOMPM_CONFIG_FILE);
        if ($raw_cfg !== false) {
            // Handle both uppercase and lowercase config keys for compatibility
            // Device settings
            $config['device_ip'] = isset($raw_cfg['DEVICE_IP']) ? $raw_cfg['DEVICE_IP'] : (isset($raw_cfg['device_ip']) ? $raw_cfg['device_ip'] : "");
            $config['device_name'] = isset($raw_cfg['DEVICE_NAME']) ? $raw_cfg['DEVICE_NAME'] : (isset($raw_cfg['device_name']) ? $raw_cfg['device_name'] : "Unraid Server PM");
            
            // Cost settings
            $config['costs_price'] = isset($raw_cfg['COSTS_PRICE']) ? (float)$raw_cfg['COSTS_PRICE'] : (isset($raw_cfg['costs_price']) ? (float)$raw_cfg['costs_price'] : 0.0);
            $config['costs_unit'] = isset($raw_cfg['COSTS_UNIT']) ? $raw_cfg['COSTS_UNIT'] : (isset($raw_cfg['costs_unit']) ? $raw_cfg['costs_unit'] : "GBP");
            
            // For backward compatibility, also set uppercase keys
            $config['DEVICE_IP'] = $config['device_ip'];
            $config['DEVICE_NAME'] = $config['device_name'];
            $config['COSTS_PRICE'] = $config['costs_price'];
            $config['COSTS_UNIT'] = $config['costs_unit'];
            
            // Optional: allow user to specify sensor paths, default to 'power' and 'daily_energy'
            $config['POWER_SENSOR_PATH'] = !empty($raw_cfg['POWER_SENSOR_PATH']) ? $raw_cfg['POWER_SENSOR_PATH'] : 'power';
            $config['DAILY_ENERGY_SENSOR_PATH'] = !empty($raw_cfg['DAILY_ENERGY_SENSOR_PATH']) ? $raw_cfg['DAILY_ENERGY_SENSOR_PATH'] : 'daily_energy';
        }
    }
    return $config;
}

/**
 * Load JSON data from a file
 * 
 * @param string $file_path Path to the JSON file
 * @return array|null Decoded JSON data or null on error
 */
function esphomepm_load_json_data($file_path) {
    if (!file_exists($file_path)) {
        return null;
    }
    $json_content = file_get_contents($file_path);
    if ($json_content === false) {
        esphomepm_log_error("Failed to read data file: $file_path", 'ERROR', 'json_data');
        return null;
    }
    $data = json_decode($json_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        esphomepm_log_error("JSON decode error for $file_path: " . json_last_error_msg(), 'ERROR', 'json_data');
        return null;
    }
    return $data;
}

/**
 * Save data to a JSON file
 * 
 * @param string $file_path Path to the JSON file
 * @param array $data Data to save
 * @return bool True on success, false on failure
 */
function esphomepm_save_json_data($file_path, $data) {
    $json_output = json_encode($data, JSON_PRETTY_PRINT);
    if ($json_output === false) {
        esphomepm_log_error("JSON encode error: " . json_last_error_msg(), 'ERROR', 'json_data');
        return false;
    }
    if (file_put_contents($file_path, $json_output) === false) {
        esphomepm_log_error("Failed to write data file: $file_path", 'ERROR', 'json_data');
        return false;
    }
    return true;
}

/**
 * Fetch sensor data from an ESPHome device
 * 
 * @param string $device_ip IP address of the ESPHome device
 * @param string $sensor_path Path to the sensor
 * @param int $timeout Request timeout in seconds
 * @param bool $return_full_response Whether to return the full response array or just the value
 * @return array|float|null Sensor data with value and error info or null on failure
 */
function esphomepm_fetch_sensor_data($device_ip, $sensor_path, $timeout = 5, $return_full_response = false) {
    if (empty($device_ip) || empty($sensor_path)) {
        esphomepm_log_error("fetch_sensor_data - Empty device IP or sensor path.", 'ERROR', 'sensor_data');
        return $return_full_response ? ['value' => 0, 'error' => 'Device IP or sensor path not configured'] : null;
    }
    
    $url = "http://$device_ip/sensor/$sensor_path";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout > 1 ? $timeout - 1 : 1,
        CURLOPT_FAILONERROR => false, // We want to check HTTP code manually
        CURLOPT_HEADER => false,
        CURLOPT_USERAGENT => 'ESPHomePM-Unraid-Plugin/1.1' // Updated user agent
    ]);
    
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_errno = curl_errno($ch);
    $curl_error_message = curl_error($ch);

    if ($curl_errno) {
        esphomepm_log_error("cURL Error for $url: [$curl_errno] $curl_error_message", 'ERROR', 'sensor_data');
        curl_close($ch);
        return $return_full_response ? ['value' => 0, 'error' => "cURL error $curl_errno ($curl_error_message)"] : null;
    }
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        // Try to parse JSON response
        $sensor_data = json_decode($response_body, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            // Standard ESPHome API format
            if (isset($sensor_data['value'])) {
                return $return_full_response ? 
                    ['value' => (float)$sensor_data['value'], 'error' => null] : 
                    (float)$sensor_data['value'];
            } 
            // Alternative format sometimes used
            else if (isset($sensor_data['state'])) {
                return $return_full_response ? 
                    ['value' => (float)$sensor_data['state'], 'error' => null] : 
                    (float)$sensor_data['state'];
            }
        } 
        // Try direct numeric response (some ESPHome configs)
        else if (is_numeric(trim($response_body))) {
            return $return_full_response ? 
                ['value' => (float)trim($response_body), 'error' => null] : 
                (float)trim($response_body);
        }
        
        esphomepm_log_error("Invalid response format from $url. Response: $response_body", 'ERROR', 'sensor_data');
        return $return_full_response ? ['value' => 0, 'error' => "Invalid response format"] : null;
    } else {
        esphomepm_log_error("HTTP Error $http_code from $url. Response: $response_body", 'ERROR', 'sensor_data');
        return $return_full_response ? ['value' => 0, 'error' => "HTTP error $http_code"] : null;
    }
}

/**
 * Initialize a plugin script:
 *  - set up error logging for specified component
 *  - optionally send JSON headers (dependency on ui_utils.php for esphomepm_set_json_headers)
 *  - load and return the config array
 */
function esphomepm_init_script(string $component, bool $jsonResponse = false): array {
    esphomepm_setup_error_logging($component);
    if ($jsonResponse) {
        // This function is defined in ui_utils.php. 
        // The master functions.php will include ui_utils.php before this would be called typically.
        if (function_exists('esphomepm_set_json_headers')) {
            esphomepm_set_json_headers();
        } else {
            esphomepm_log_error('esphomepm_set_json_headers() not found. Ensure ui_utils.php is included.', 'WARNING', 'init_script');
        }
    }
    return esphomepm_load_config();
}

/**
 * Helper wrapper for status.php moved from inline definitions
 */
function esphomepm_get_sensor_value($sensor, $device_ip, $timeout = 2) {
    // This now directly uses the more detailed return from fetch_sensor_data
    return esphomepm_fetch_sensor_data($device_ip, $sensor, $timeout, true);
}

/**
 * Load raw historical data from JSON file
 * This is essentially an alias for esphomepm_load_json_data(ESPHOMPM_DATA_FILE)
 */
function esphomepm_load_historical_data() {
    return esphomepm_load_json_data(ESPHOMPM_DATA_FILE);
}

/**
 * Initialize or validate the data file structure
 * 
 * @return array|null The initialized data structure or null on critical failure
 */
function esphomepm_initialize_data_file($recursion_depth = 0) {
    // Add recursion depth limit
    if ($recursion_depth > 2) {
        esphomepm_log_error("Maximum recursion depth reached when initializing data file", 'CRITICAL', 'data_file');
        return null;
    }
    if (!file_exists(ESPHOMPM_DATA_FILE)) {
        $default_data = [
            'data_format_version' => ESPHOMPM_DATA_FORMAT_VERSION,
            'current_month' => [
                'month_year' => date('Y-m'),
                'daily_records' => [],
                'total_energy_kwh_completed_days' => 0.0,
                'total_cost_completed_days' => 0.0
            ],
            'historical_months' => [],
            'overall_totals' => [
                'monitoring_start_date' => date('Y-m-d'),
                'total_energy_kwh_all_time' => 0.0,
                'total_cost_all_time' => 0.0
            ]
        ];
        if (!esphomepm_save_json_data(ESPHOMPM_DATA_FILE, $default_data)) {
            esphomepm_log_error("Failed to create and initialize data file: " . ESPHOMPM_DATA_FILE, 'CRITICAL', 'data_file');
            return null;
        }
        esphomepm_log_error("Data file created and initialized: " . ESPHOMPM_DATA_FILE, 'INFO', 'data_file');
        return $default_data;
    }

    $data = esphomepm_load_json_data(ESPHOMPM_DATA_FILE);
    if ($data === null) {
        esphomepm_log_error("Data file is unreadable or corrupt. Attempting to rename and reinitialize.", 'ERROR', 'data_file');
        $backup_file_name = ESPHOMPM_DATA_FILE . '.' . date('YmdHis') . '.bak';
        if (rename(ESPHOMPM_DATA_FILE, $backup_file_name)) {
            esphomepm_log_error("Corrupt data file renamed to $backup_file_name. Reinitializing.", 'INFO', 'data_file');
            return esphomepm_initialize_data_file($recursion_depth + 1); // Recursive call with incremented depth
        } else {
            esphomepm_log_error("Failed to rename corrupt data file. Critical error.", 'CRITICAL', 'data_file');
            return null;
        }
    }
    // Add more validation if needed (e.g., version check, structure check)
    return $data;
}

/**
 * Validate if the current time is appropriate for the cron job to run
 * 
 * @return bool True if it's an appropriate time, false otherwise
 */
function esphomepm_validate_cron_time() {
    $current_hour = (int)date('H');
    $current_minute = (int)date('i');

    // Primary run window: 23:58
    // Retry run window: 23:59 (implicitly handled by retry logic in data-handler.php)
    if ($current_hour === 23 && $current_minute === 58) {
        return true; // Valid time for primary run
    }
    
    // If it's not the primary run time, log it unless it's a known retry scenario (which data-handler.php checks via ENV var)
    if (!isset($_SERVER['ESPHOMEPM_RETRY']) || $_SERVER['ESPHOMEPM_RETRY'] == 0) {
         esphomepm_log_error("Cron job triggered at an unexpected time: {$current_hour}:{$current_minute}. Expected 23:58 for primary run.", 'WARNING', 'cron_job');
    }
    return false; // Not the primary execution time
}
/**
 * Log the results of a cron job execution to a dedicated cron log file.
 *
 * @param array $data An associative array containing data to log. Expected keys:
 *                    'retry_count' (int, optional, default 0)
 *                    'target_date' (string, optional, default current date)
 *                    'cost_unit'   (string, e.g., "USD", "EUR", required if cost data is present)
 *                    'yesterday_energy' (float, optional)
 *                    'yesterday_cost'   (float, optional)
 *                    'month_energy'     (float, optional)
 *                    'month_cost'       (float, optional)
 *                    'total_energy'     (float, optional)
 *                    'total_cost'       (float, optional)
 * @return bool True on success, false on failure.
 */
function esphomepm_log_cron_execution(array $data): bool {
    $lines = [];
    $ts = date('Y-m-d H:i:s');
    $lines[] = "[$ts] Daily Update";
    $attempt = $data['retry_count'] ?? 0;
    $lines[] = 'Attempt: ' . ($attempt > 0 ? "Retry #$attempt" : 'Initial');
    $lines[] = 'Date: ' . ($data['target_date'] ?? date('Y-m-d'));

    // Ensure cost_unit is available if cost data is being logged
    $cost_unit = $data['cost_unit'] ?? 'N/A';

    $labels = [
        'yesterday_energy' => "Yesterday's Energy: %s kWh",
        'yesterday_cost'   => "Yesterday's Cost: %s {$cost_unit}",
        'month_energy'     => "Current Month Energy: %s kWh",
        'month_cost'       => "Current Month Cost: %s {$cost_unit}",
        'total_energy'     => "Total Energy: %s kWh",
        'total_cost'       => "Total Cost: %s {$cost_unit}",
    ];
    foreach ($labels as $key => $fmt) {
        if (isset($data[$key])) {
            $val = $data[$key];
             // Format numbers to a reasonable precision if they are numeric
            if (is_numeric($val)) {
                if (strpos($key, 'cost') !== false) {
                    $val = number_format((float)$val, 2); // Costs to 2 decimal places
                } elseif (strpos($key, 'energy') !== false) {
                    $val = number_format((float)$val, 3); // Energy to 3 decimal places
                }
            }
            $lines[] = sprintf($fmt, $val);
        } else {
            // Optionally skip or show 'Not available' if data point is missing
            // For cleaner logs, we can skip if not present, or explicitly state 'Not available'
            // $lines[] = sprintf($fmt, 'Not available'); 
        }
    }
    
    if (isset($data['message'])) {
        $lines[] = "Status: " . $data['message'];
    }

    $entry = implode("\n", $lines) . "\n\n"; // Add an extra newline for readability between entries
    $dir = dirname(ESPHOMPM_LOG_FILE); // ESPHOMPM_LOG_FILE is defined in core_utils.php
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            esphomepm_log_error("Failed to create cron log dir: $dir", 'ERROR', 'cron_log'); // esphomepm_log_error is in core_utils.php
            return false;
        }
    }
    if (file_put_contents(ESPHOMPM_LOG_FILE, $entry, FILE_APPEND) === false) {
        esphomepm_log_error("Failed to write cron log: " . ESPHOMPM_LOG_FILE, 'ERROR', 'cron_log');
        return false;
    }
    return true;
}
?>
