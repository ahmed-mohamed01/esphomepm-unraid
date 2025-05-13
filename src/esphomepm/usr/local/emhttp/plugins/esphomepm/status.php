<?php
// Enable error reporting for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Log errors to a file for debugging
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/esphomepm_status_error.log'); // Changed log file name for clarity

// Set content type to JSON
header('Content-Type: application/json');

// Configuration and constants
define('ESPHOMPM_PLUGIN_NAME', 'esphomepm');
define('ESPHOMPM_CONFIG_FILE', '/boot/config/plugins/' . ESPHOMPM_PLUGIN_NAME . '/' . ESPHOMPM_PLUGIN_NAME . '.cfg');
define('ESPHOMPM_DATA_FILE', '/boot/config/plugins/' . ESPHOMPM_PLUGIN_NAME . '/esphomepm_data.json');

// Load configuration
$esphomepm_cfg = [];
if (file_exists(ESPHOMPM_CONFIG_FILE)) {
    $raw_cfg = parse_ini_file(ESPHOMPM_CONFIG_FILE);
    if ($raw_cfg !== false) {
        $esphomepm_cfg = $raw_cfg;
    }
}

// Initialize config variables with defaults
$device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : "";
$costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : "0.0"; // Default as string
$costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : "";    // Default as empty string
$power_sensor_path = !empty($esphomepm_cfg['POWER_SENSOR_PATH']) ? $esphomepm_cfg['POWER_SENSOR_PATH'] : 'power';
$daily_energy_sensor_path = !empty($esphomepm_cfg['DAILY_ENERGY_SENSOR_PATH']) ? $esphomepm_cfg['DAILY_ENERGY_SENSOR_PATH'] : 'daily_energy';

// Function to get sensor value with retry and error handling
function getSensorValue($sensor, $device_ip, $timeout = 2) { // Default timeout for sensor reads
    if (empty($device_ip)) {
        error_log("getSensorValue: Empty device IP for sensor $sensor");
        return ['value' => 0, 'error' => 'Device IP not configured'];
    }
    
    try {
        $url = "http://$device_ip/sensor/$sensor";  // Standard ESPHome API format
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout -1 > 0 ? $timeout -1 : 1); // Connect timeout slightly less
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ESPHomePM-Unraid-Plugin/1.1'); // Updated version
        
        $response = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error_message = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($curl_errno) {
            error_log("getSensorValue: cURL error $curl_errno ($curl_error_message) for $url");
            return ['value' => 0, 'error' => "cURL error $curl_errno ($curl_error_message)"];
        }
        
        if ($http_code != 200) {
            error_log("getSensorValue: HTTP error $http_code for $url. Response: $response");
            return ['value' => 0, 'error' => "HTTP error $http_code"];
        }
        
        error_log("Raw response from $url: $response");
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (is_numeric(trim($response))) { // Trim whitespace before numeric check
                error_log("getSensorValue: Numeric value detected: $response");
                return ['value' => floatval(trim($response)), 'error' => null];
            }
            error_log("getSensorValue: JSON parse error: " . json_last_error_msg() . " for response: $response");
            return ['value' => 0, 'error' => "JSON parse error"];
        }
        
        if (isset($data['value'])) {
            return ['value' => floatval($data['value']), 'error' => null];
        } else if (isset($data['state'])) {
            return ['value' => floatval($data['state']), 'error' => null];
        } else if (is_numeric($data)) {
            return ['value' => floatval($data), 'error' => null];
        }
        
        error_log("getSensorValue: Unexpected data format for $sensor: " . json_encode($data));
        return ['value' => 0, 'error' => "Unexpected data format"];
    } catch (Exception $e) {
        error_log("getSensorValue: Exception for $sensor: " . $e->getMessage());
        return ['value' => 0, 'error' => "Exception"];
    }
}

// Handle graph point request
if (isset($_GET['graph_point']) && $_GET['graph_point'] === 'true') {
    if (empty($device_ip)) {
        echo json_encode(['power' => 0, 'error' => 'ESPHome Device IP missing']);
        exit;
    }
    $power_data = getSensorValue("power", $device_ip, 1); // Shorter timeout for graph point
    echo json_encode(['power' => $power_data['value'], 'error' => $power_data['error']]);
    exit;
}

// Function to load historical data from JSON file
function load_historical_data() {
    if (!file_exists(ESPHOMPM_DATA_FILE)) {
        return null;
    }
    $json_content = file_get_contents(ESPHOMPM_DATA_FILE);
    if ($json_content === false) {
        error_log("ESPHomePM: Failed to read data file: " . ESPHOMPM_DATA_FILE);
        return null;
    }
    $data = json_decode($json_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ESPHomePM: JSON decode error for " . ESPHOMPM_DATA_FILE . ": " . json_last_error_msg());
        return null;
    }
    return $data;
}

// Standard data request logic
if (empty($device_ip)) {
    echo json_encode([
        'power' => 0, 'today_energy' => 0,
        'daily_cost' => 0, 'monthly_cost_est' => 0,
        'costs_price' => $costs_price, 'costs_unit' => $costs_unit,
        'historical_data_available' => false,
        'error' => 'ESPHome Device IP missing'
    ]);
    exit;
}

// --- Fetch data from ESPHome device for standard request ---
$error_messages = [];

$power_result = getSensorValue($power_sensor_path, $device_ip);
$daily_energy_result = getSensorValue($daily_energy_sensor_path, $device_ip);

$power = 0;
if (isset($power_result['value'])) {
    $power = $power_result['value'];
}
if (isset($power_result['error']) && $power_result['error'] !== null) {
    $error_messages[] = "Power: " . $power_result['error'];
}

$daily_energy = 0;
if (isset($daily_energy_result['value'])) {
    $daily_energy = $daily_energy_result['value'];
}
if (isset($daily_energy_result['error']) && $daily_energy_result['error'] !== null) {
    $error_messages[] = "Daily Energy: " . $daily_energy_result['error'];
}

// Calculations
$costs_price_numeric = is_numeric($costs_price) ? (float)$costs_price : 0.0; // Ensure costs_price is numeric
$daily_cost = $daily_energy * $costs_price_numeric;

// --- Load historical data ---
$historical_data = load_historical_data();
$historical_data_available = ($historical_data !== null);

// Default values for historical data
$current_month_energy_completed_days = 0.0;
$current_month_cost_completed_days = 0.0;
$historical_months = [];
$overall_total_energy = 0.0;
$overall_total_cost = 0.0;
$monitoring_start_date = date('Y-m-d'); // Default to today if no historical data

// Extract historical data if available
if ($historical_data_available) {
    // Current month completed days (excluding today)
    if (isset($historical_data['current_month']['total_energy_kwh_completed_days'])) {
        $current_month_energy_completed_days = (float)$historical_data['current_month']['total_energy_kwh_completed_days'];
    }
    if (isset($historical_data['current_month']['total_cost_completed_days'])) {
        $current_month_cost_completed_days = (float)$historical_data['current_month']['total_cost_completed_days'];
    }
    
    // Historical months
    if (isset($historical_data['historical_months']) && is_array($historical_data['historical_months'])) {
        $historical_months = $historical_data['historical_months'];
    }
    
    // Overall totals
    if (isset($historical_data['overall_totals']['total_energy_kwh_all_time'])) {
        $overall_total_energy = (float)$historical_data['overall_totals']['total_energy_kwh_all_time'];
    }
    if (isset($historical_data['overall_totals']['total_cost_all_time'])) {
        $overall_total_cost = (float)$historical_data['overall_totals']['total_cost_all_time'];
    }
    if (isset($historical_data['overall_totals']['monitoring_start_date'])) {
        $monitoring_start_date = $historical_data['overall_totals']['monitoring_start_date'];
    }
}

// Calculate current month totals including today's values
$current_month_energy_total = $current_month_energy_completed_days + $daily_energy;
$current_month_cost_total = $current_month_cost_completed_days + $daily_cost;

// Calculate overall totals including today's values
$overall_total_energy_with_today = $overall_total_energy + $daily_energy;
$overall_total_cost_with_today = $overall_total_cost + $daily_cost;

// Prepare response
$response_data = [
    // Live data
    'power' => $power,
    'today_energy' => $daily_energy,
    'daily_cost' => round($daily_cost, 2),
    
    // Configuration
    'costs_price' => $costs_price,
    'costs_unit' => $costs_unit,
    
    // Current month data
    'current_month_energy_completed_days' => round($current_month_energy_completed_days, 3),
    'current_month_cost_completed_days' => round($current_month_cost_completed_days, 2),
    'current_month_energy_total' => round($current_month_energy_total, 3), // Including today
    'current_month_cost_total' => round($current_month_cost_total, 2),    // Including today
    
    // Historical and overall data
    'historical_data_available' => $historical_data_available,
    'historical_months' => $historical_months,
    'overall_total_energy' => round($overall_total_energy_with_today, 3),
    'overall_total_cost' => round($overall_total_cost_with_today, 2),
    'monitoring_start_date' => $monitoring_start_date,
    
    // For backward compatibility
    'monthly_cost_est' => round($current_month_cost_total, 2), // Now using actual data instead of daily*30
    
    // Error information
    'error' => empty($error_messages) ? null : implode('; ', $error_messages)
];

echo json_encode($response_data);
?>