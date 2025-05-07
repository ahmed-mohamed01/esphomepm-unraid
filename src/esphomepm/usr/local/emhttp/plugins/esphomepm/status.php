<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set headers to prevent caching and allow cross-origin requests
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Test mode - return dummy data for testing
if (isset($_GET['test']) && $_GET['test'] === 'true') {
    header('Content-Type: application/json');
    echo json_encode([
        'Power' => 35.08,
        'Total' => 0.6,
        'Voltage' => 242.6,
        'Current' => 0.244,
        'Costs_Price' => '0.27',
        'Costs_Unit' => 'GBP'
    ]);
    exit;
}

// Load configuration using parse_plugin_cfg
$esphomepm_cfg = parse_plugin_cfg("esphomepm", true);

// Get configuration values with defaults
$esphomepm_device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : "";
$esphomepm_costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : "0.27";
$esphomepm_costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : "GBP";

// Debug information
$debug = [];
$debug['device_ip'] = $esphomepm_device_ip;
$debug['php_version'] = phpversion();
$debug['server_software'] = $_SERVER['SERVER_SOFTWARE'];

// Check if device IP is set
if (empty($esphomepm_device_ip)) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'ESPHome Device IP missing',
        'debug' => $debug
    ]);
    exit;
}

// Set the base URL for the ESPHome device
$baseUrl = "http://" . $esphomepm_device_ip;
$debug['base_url'] = $baseUrl;

// Initialize curl
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects

// Function to get sensor value - using the exact format from the curl response
function getSensorValue($ch, $url, &$debug, $sensorName) {
    curl_setopt($ch, CURLOPT_URL, $url);
    $response = curl_exec($ch);
    
    $debug[$sensorName . '_url'] = $url;
    $debug[$sensorName . '_response'] = substr($response, 0, 100); // Limit debug output
    $debug[$sensorName . '_http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $debug[$sensorName . '_error'] = curl_error($ch);
        return 0;
    }
    
    // Parse the JSON response - format: {"id":"sensor-power","value":35.08036,"state":"35 W"}
    $data = json_decode($response, true);
    if (isset($data['value'])) {
        return floatval($data['value']);
    } elseif (isset($data['state'])) {
        // Try to extract numeric value from state
        $numericValue = preg_replace('/[^0-9.]/', '', $data['state']);
        return floatval($numericValue);
    }
    
    return 0;
}

// Try to connect to the device first
curl_setopt($ch, CURLOPT_URL, $baseUrl);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

$debug['main_connection'] = [
    'http_code' => $httpCode,
    'curl_error' => curl_error($ch),
    'response' => substr($response, 0, 100)
];

// If we can't connect to the device
if (curl_errno($ch) || $httpCode >= 400) {
    curl_close($ch);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Cannot connect to ESPHome device at ' . $baseUrl,
        'Power' => 0,
        'Total' => 0,
        'Voltage' => 0,
        'Current' => 0,
        'Costs_Price' => $esphomepm_costs_price,
        'Costs_Unit' => $esphomepm_costs_unit,
        'debug' => $debug
    ]);
    exit;
}

// Get Power - we know this works from the curl test
$power = getSensorValue($ch, $baseUrl . "/sensor/power", $debug, 'power');

// Try different variations of daily energy sensor names
$daily_energy = 0;
$energy_sensors = ['daily_energy', 'energy_today', 'today_energy', 'daily_consumption'];
foreach ($energy_sensors as $sensor) {
    $value = getSensorValue($ch, $baseUrl . "/sensor/" . $sensor, $debug, $sensor);
    if ($value > 0) {
        $daily_energy = $value;
        $debug['used_energy_sensor'] = $sensor;
        break;
    }
}

// Get Voltage
$voltage = getSensorValue($ch, $baseUrl . "/sensor/voltage", $debug, 'voltage');

// Get Current
$current = getSensorValue($ch, $baseUrl . "/sensor/current", $debug, 'current');

curl_close($ch);

// Log the values for debugging
error_log("ESPHome values - Power: $power, Daily Energy: $daily_energy, Voltage: $voltage, Current: $current");

$response = array(
    'Power' => $power,
    'Total' => $daily_energy,
    'Voltage' => $voltage,
    'Current' => $current,
    'Costs_Price' => $esphomepm_costs_price,
    'Costs_Unit' => $esphomepm_costs_unit,
    'debug' => $debug
);

header('Content-Type: application/json');
echo json_encode($response);
?>