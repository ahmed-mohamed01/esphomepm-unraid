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
 * Simplified cron job logging
 * 
 * @param array $data The data to log (previous day's energy, current totals, etc.)
 * @return bool True on success, false on failure
 */
function esphomepm_log_cron_execution(array $data): bool {
    $lines = [];
    $ts = date('Y-m-d H:i:s');
    $lines[] = "[$ts] Daily Update";
    $attempt = $data['retry_count'] ?? 0;
    $lines[] = 'Attempt: ' . ($attempt > 0 ? "Retry #$attempt" : 'Initial');
    $lines[] = 'Date: ' . ($data['target_date'] ?? date('Y-m-d'));

    $labels = [
        'yesterday_energy' => "Yesterday's Energy: %s kWh",
        'yesterday_cost'   => "Yesterday's Cost: %s {$data['cost_unit']}",
        'month_energy'     => "Current Month Energy: %s kWh",
        'month_cost'       => "Current Month Cost: %s {$data['cost_unit']}",
        'total_energy'     => "Total Energy: %s kWh",
        'total_cost'       => "Total Cost: %s {$data['cost_unit']}",
    ];
    foreach ($labels as $key => $fmt) {
        $val = $data[$key] ?? 'Not available';
        $lines[] = sprintf($fmt, $val);
    }

    $entry = implode("\n", $lines) . "\n";
    $dir = dirname(ESPHOMPM_LOG_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        esphomepm_log_error("Failed to create cron log dir: $dir", 'ERROR', 'cron_log');
        return false;
    }
    if (file_put_contents(ESPHOMPM_LOG_FILE, $entry, FILE_APPEND) === false) {
        esphomepm_log_error("Failed to write cron log: " . ESPHOMPM_LOG_FILE, 'ERROR', 'cron_log');
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

/**
 * Initialize a plugin script:
 *  - set up error logging for specified component
 *  - optionally send JSON headers
 *  - load and return the config array
 */
function esphomepm_init_script(string $component, bool $jsonResponse = false): array {
    esphomepm_setup_error_logging($component);
    if ($jsonResponse) {
        esphomepm_set_json_headers();
    }
    return esphomepm_load_config();
}

/**
 * Helper wrappers for status.php moved from inline definitions
 */
function esphomepm_get_sensor_value($sensor, $device_ip, $timeout = 2) {
    return esphomepm_fetch_sensor_data($device_ip, $sensor, $timeout, true);
}

function esphomepm_load_historical_data() {
    return esphomepm_load_json_data(ESPHOMPM_DATA_FILE);
}

// --- Core data handling functions ---

/**
 * Fetch live sensor data & compute daily cost
 *
 * @param array $config Plugin configuration
 * @return array ['power'=>float,'today_energy'=>float,'daily_cost'=>float,'errors'=>array]
 */
function esphomepm_get_live_data(array $config): array {
    $power_res = esphomepm_fetch_sensor_data($config['DEVICE_IP'], $config['POWER_SENSOR_PATH'], 5, true);
    $energy_res = esphomepm_fetch_sensor_data($config['DEVICE_IP'], $config['DAILY_ENERGY_SENSOR_PATH'], 5, true);
    $power = isset($power_res['value']) ? (float)$power_res['value'] : 0.0;
    $daily_energy = isset($energy_res['value']) ? (float)$energy_res['value'] : 0.0;
    $errors = [];
    if (!empty($power_res['error'])) $errors[] = 'Power: ' . $power_res['error'];
    if (!empty($energy_res['error'])) $errors[] = 'Daily Energy: ' . $energy_res['error'];
    $price = is_numeric($config['COSTS_PRICE']) ? (float)$config['COSTS_PRICE'] : 0.0;
    $daily_cost = $daily_energy * $price;
    return [
        'power' => $power,
        'today_energy' => $daily_energy,
        'daily_cost' => round($daily_cost, 2),
        'errors' => $errors
    ];
}

/**
 * Load raw historical data from JSON file
 *
 * @return array Historical data or empty array
 */
function esphomepm_get_historical_data(): array {
    $data = esphomepm_load_json_data(ESPHOMPM_DATA_FILE);
    return is_array($data) ? $data : [];
}

/**
 * Build a unified response combining live & historical data
 *
 * @param array $config Plugin configuration
 * @return array Response payload
 */
function esphomepm_build_summary(array $config): array {
    $live = esphomepm_get_live_data($config);
    $raw = esphomepm_get_historical_data();

    // Completed-days totals
    $cm_e = (float)($raw['current_month']['total_energy_kwh_completed_days'] ?? 0.0);
    $cm_c = (float)($raw['current_month']['total_cost_completed_days'] ?? 0.0);
    // Historical months
    $hist_months = is_array($raw['historical_months'] ?? null) ? $raw['historical_months'] : [];
    // Overall totals
    $ot_e = (float)($raw['overall_totals']['total_energy_kwh_all_time'] ?? 0.0);
    $ot_c = (float)($raw['overall_totals']['total_cost_all_time'] ?? 0.0);
    $msd = $raw['overall_totals']['monitoring_start_date'] ?? date('Y-m-d');

    // Include today's data
    $cm_e_tot = $cm_e + $live['today_energy'];
    $cm_c_tot = $cm_c + $live['daily_cost'];
    $ot_e += $live['today_energy'];
    $ot_c += $live['daily_cost'];
    // Average daily energy
    $day = (int)date('j');
    $avg_e = $day ? round($cm_e_tot / $day, 3) : 0.0;

    return [
        'power' => $live['power'],            'today_energy' => $live['today_energy'],
        'daily_cost' => $live['daily_cost'],
        'costs_price' => $config['COSTS_PRICE'], 'costs_unit' => $config['COSTS_UNIT'],
        'current_month_energy_completed_days' => round($cm_e, 3),
        'current_month_cost_completed_days' => round($cm_c, 2),
        'current_month_energy_total' => round($cm_e_tot, 3),
        'current_month_cost_total' => round($cm_c_tot, 2),
        'average_daily_energy' => $avg_e,
        'current_month' => [
            'month_year' => $raw['current_month']['month_year'] ?? date('Y-m'),
            'total_energy_kwh_completed_days' => round($cm_e, 3),
            'total_cost_completed_days' => round($cm_c, 2)
        ],
        'historical_data_available' => !empty($raw),
        'historical_months' => $hist_months,
        'overall_total_energy' => round($ot_e, 3),
        'overall_total_cost' => round($ot_c, 2),
        'monitoring_start_date' => $msd,
        'monthly_cost_est' => round($cm_c_tot, 2),
        'error' => empty($live['errors']) ? null : implode('; ', $live['errors'])
    ];
}

/**
 * Wrapper to update data file (cron or manual)
 *
 * @param int $retry_count
 * @param string|null $target_date
 * @return bool
 */
function esphomepm_update_data_file(int $retry_count = 0, string $target_date = null): bool {
    return update_power_consumption_data($retry_count, $target_date);
}
