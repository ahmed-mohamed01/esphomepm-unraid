<?php
// Enable error display for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set content type to JSON
header('Content-Type: application/json');

// Set cache control headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Test mode - return dummy data for testing
if (isset($_GET['test']) && $_GET['test'] === 'true') {
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

// Load configuration directly from the file
$config_file = "/boot/config/plugins/esphomepm/esphomepm.cfg";
$esphomepm_cfg = [];

if (file_exists($config_file)) {
    $esphomepm_cfg = parse_ini_file($config_file);
}

// Get configuration values
$device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : "";
$costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : "0.27";
$costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : "GBP";

// Check if device IP is set
if (empty($device_ip)) {
    echo json_encode([
        'error' => 'ESPHome Device IP missing',
        'Power' => 0,
        'Total' => 0,
        'Voltage' => 0,
        'Current' => 0,
        'Costs_Price' => $costs_price,
        'Costs_Unit' => $costs_unit
    ]);
    exit;
}

// Initialize curl
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Base URL for the ESPHome device
$baseUrl = "http://" . $device_ip;

// Function to get sensor value
function getSensorValue($ch, $url) {
    curl_setopt($ch, CURLOPT_URL, $url);
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        return 0;
    }
    
    $data = json_decode($response, true);
    if (isset($data['value'])) {
        return floatval($data['value']);
    }
    
    return 0;
}

// Check if we can connect to the device
curl_setopt($ch, CURLOPT_URL, $baseUrl);
$response = curl_exec($ch);

if (curl_errno($ch)) {
    curl_close($ch);
    echo json_encode([
        'error' => 'Cannot connect to ESPHome device',
        'Power' => 0,
        'Total' => 0,
        'Voltage' => 0,
        'Current' => 0,
        'Costs_Price' => $costs_price,
        'Costs_Unit' => $costs_unit
    ]);
    exit;
}

// Get sensor values
$power = getSensorValue($ch, $baseUrl . "/sensor/power");
$daily_energy = getSensorValue($ch, $baseUrl . "/sensor/daily_energy");

// Try alternative sensor name if daily_energy returns 0
if ($daily_energy == 0) {
    $daily_energy = getSensorValue($ch, $baseUrl . "/sensor/energy_today");
}

$voltage = getSensorValue($ch, $baseUrl . "/sensor/voltage");
$current = getSensorValue($ch, $baseUrl . "/sensor/current");

curl_close($ch);

// Return the data
echo json_encode([
    'Power' => $power,
    'Total' => $daily_energy,
    'Voltage' => $voltage,
    'Current' => $current,
    'Costs_Price' => $costs_price,
    'Costs_Unit' => $costs_unit
]);
?>