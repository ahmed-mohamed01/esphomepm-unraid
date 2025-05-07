<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set headers to prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Load configuration using parse_plugin_cfg
$esphomepm_cfg = parse_plugin_cfg("esphomepm", true);

// Get configuration values with defaults
$esphomepm_device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : "";
$esphomepm_costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : "0.27";
$esphomepm_costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : "GBP";

// Debug information
$debug = [];
$debug['device_ip'] = $esphomepm_device_ip;

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

// Function to get sensor value
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
    
    $data = json_decode($response, true);
    return isset($data['value']) ? floatval($data['value']) : 0;
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

// Get Power
$power = getSensorValue($ch, $baseUrl . "/sensor/power", $debug, 'power');

// Get Daily Energy (using daily_energy or energy_today based on your device)
$daily_energy = getSensorValue($ch, $baseUrl . "/sensor/daily_energy", $debug, 'daily_energy');
if ($daily_energy == 0) {
    // Try alternative sensor name
    $daily_energy = getSensorValue($ch, $baseUrl . "/sensor/energy_today", $debug, 'energy_today');
}

// Get Voltage
$voltage = getSensorValue($ch, $baseUrl . "/sensor/voltage", $debug, 'voltage');

// Get Current
$current = getSensorValue($ch, $baseUrl . "/sensor/current", $debug, 'current');

curl_close($ch);

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