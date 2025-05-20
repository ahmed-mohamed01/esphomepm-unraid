<?php
/**
 * ESPHomePM Plugin - Common Functions
 * 
 * This file contains shared functions used across the ESPHomePM plugin
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
    ini_set('error_log', "/tmp/esphomepm_{$component}_error.log");
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
        CURLOPT_USERAGENT => 'ESPHomePM-Unraid-Plugin/1.1'
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
            esphomepm_log_error("Failed to initialize data file " . ESPHOMPM_DATA_FILE, 'CRITICAL', 'data_file');
            return null;
        }
        return $default_data;
    }
    
    $data = esphomepm_load_json_data(ESPHOMPM_DATA_FILE);
    if ($data === null) { // File exists but is unreadable/corrupt
         esphomepm_log_error("Data file " . ESPHOMPM_DATA_FILE . " is corrupt or unreadable. Attempting to backup and re-initialize.", 'CRITICAL', 'data_file');
         // Basic backup
         @copy(ESPHOMPM_DATA_FILE, ESPHOMPM_DATA_FILE . '.' . time() . '.corrupt.bak');
         // Recursive call to create a new one with incremented depth
         return esphomepm_initialize_data_file($recursion_depth + 1);
    }

    // Version check and structure validation
    if (!isset($data['data_format_version']) || $data['data_format_version'] !== ESPHOMPM_DATA_FORMAT_VERSION) {
        esphomepm_log_error("Data file version mismatch or missing. Expected: " . ESPHOMPM_DATA_FORMAT_VERSION . ". Found: " . ($data['data_format_version'] ?? 'N/A'), 'WARNING', 'data_file');
        // Add missing top-level keys if they don't exist for basic compatibility
        if (!isset($data['current_month'])) $data['current_month'] = ['month_year' => date('Y-m'), 'daily_records' => [], 'total_energy_kwh_completed_days' => 0.0, 'total_cost_completed_days' => 0.0];
        if (!isset($data['historical_months'])) $data['historical_months'] = [];
        if (!isset($data['overall_totals'])) $data['overall_totals'] = ['monitoring_start_date' => date('Y-m-d'), 'total_energy_kwh_all_time' => 0.0, 'total_cost_all_time' => 0.0];
        $data['data_format_version'] = ESPHOMPM_DATA_FORMAT_VERSION; // Update to current version
    }
    return $data;
}

/**
 * Set JSON response headers
 */
function esphomepm_set_json_headers() {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
}

/**
 * Log cron job execution details to the cron log file
 * 
 * @param array $data The data to log (previous day's energy, current totals, etc.)
 * @return bool True on success, false on failure
 */
function esphomepm_log_cron_execution($data) {
    $timestamp = date('Y-m-d H:i:s');
    $date = date('Y-m-d');
    
    // Format the log entry
    $log_entry = "[$timestamp] Daily Update\n";
    
    // Include retry information if available
    if (isset($data['retry_count'])) {
        $log_entry .= "Attempt: " . ($data['retry_count'] > 0 ? "Retry #{$data['retry_count']}" : "Initial") . "\n";
    }
    
    // Include target date if available, otherwise use current date
    $log_entry .= "Date: " . (isset($data['target_date']) ? $data['target_date'] : $date) . "\n";
    
    if (isset($data['yesterday_energy'])) {
        $log_entry .= "Yesterday's Energy: {$data['yesterday_energy']} kWh\n";
    } else {
        $log_entry .= "Yesterday's Energy: Not available\n";
    }
    
    if (isset($data['yesterday_cost'])) {
        $log_entry .= "Yesterday's Cost: {$data['yesterday_cost']} {$data['cost_unit']}\n";
    } else {
        $log_entry .= "Yesterday's Cost: Not available\n";
    }
    
    if (isset($data['month_energy'])) {
        $log_entry .= "Current Month Energy: {$data['month_energy']} kWh\n";
    }
    
    if (isset($data['month_cost'])) {
        $log_entry .= "Current Month Cost: {$data['month_cost']} {$data['cost_unit']}\n";
    }
    
    if (isset($data['total_energy'])) {
        $log_entry .= "Total Energy: {$data['total_energy']} kWh\n";
    }
    
    if (isset($data['total_cost'])) {
        $log_entry .= "Total Cost: {$data['total_cost']} {$data['cost_unit']}\n";
    }
    
    $log_entry .= "-----------------------------------\n";
    
    // Ensure the directory exists
    $dir = dirname(ESPHOMPM_LOG_FILE);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            esphomepm_log_error("Failed to create directory for cron log: $dir", 'ERROR', 'cron_log');
            return false;
        }
    }
    
    // Append to the log file
    if (file_put_contents(ESPHOMPM_LOG_FILE, $log_entry, FILE_APPEND) === false) {
        esphomepm_log_error("Failed to write to cron log file: " . ESPHOMPM_LOG_FILE, 'ERROR', 'cron_log');
        return false;
    }
    
    return true;
}

/**
 * Validate if the current time is appropriate for the cron job to run
 * 
 * @return bool True if it's an appropriate time, false otherwise
 */
function esphomepm_validate_cron_time() {
    $current_hour = (int)date('H');
    $current_minute = (int)date('i');
    
    // Expected time is around 23:58-23:59 or just after midnight
    $is_expected_time = ($current_hour == 23 && $current_minute >= 58) || 
                       ($current_hour == 0 && $current_minute <= 5);
    
    // Also accept scheduled retry runs
    $is_retry = isset($_SERVER['ESPHOMEPM_RETRY']) && (int)$_SERVER['ESPHOMEPM_RETRY'] > 0;
    
    if (!$is_expected_time && !$is_retry) {
        esphomepm_log_error("data-handler.php executed at unexpected time: " . date('Y-m-d H:i:s'), 'WARNING', 'cron_job');
    }
    
    return $is_expected_time || $is_retry;
}

/**
 * Get the JavaScript code for updating the ESPHomePM dashboard widget
 * 
 * @return string JavaScript code for updating the dashboard widget
 */
function esphomepm_get_dashboard_javascript() {
    $js = <<<EOT
<script type="text/javascript">
$(function() {
    // Update ESPHome Power Monitor dashboard widget
    function updateESPHomePMStatus() {
        $.getJSON('/plugins/esphomepm/status.php', function(data) {
            if (!data) return;
            
            // Update current power
            updateValue('.esphomepm-current-power', data.power, 2);
            
            // Calculate average daily power if possible
            if (data.today_energy !== undefined) {
                // Get hours passed in the day
                const now = new Date();
                const hoursPassed = now.getHours() + (now.getMinutes() / 60);
                if (hoursPassed > 0) {
                    // Calculate average power in watts (kWh -> W conversion)
                    const avgPower = (data.today_energy * 1000) / hoursPassed;
                    updateValue('.esphomepm-avg-power', avgPower, 0);
                } else {
                    updateValue('.esphomepm-avg-power', 0, 0);
                }
            } else {
                updateValue('.esphomepm-avg-power', 0, 0);
            }
            
            // Energy values
            updateValue('.esphomepm-energy-today', data.today_energy, 3);
            updateValue('.esphomepm-energy-month', data.current_month_energy_total, 3);
            updateValue('.esphomepm-energy-total', data.overall_total_energy, 3);
            
            // Cost values
            updateValue('.esphomepm-costs_today', data.daily_cost, 2);
            updateValue('.esphomepm-costs_month', data.current_month_cost_total || data.monthly_cost_est, 2);
            updateValue('.esphomepm-costs_total', data.overall_total_cost, 2);
        });
    }

    // Helper function to update values with proper formatting
    function updateValue(selector, value, decimals) {
        if (value !== undefined && value !== null) {
            $(selector).html(parseFloat(value).toFixed(decimals));
        } else {
            $(selector).html('0.00');
        }
    }

    // Initial update
    updateESPHomePMStatus();
    
    // Set up automatic refresh every 10 seconds
    setInterval(updateESPHomePMStatus, 10000);
});
</script>
EOT;
    
    return $js;
}
