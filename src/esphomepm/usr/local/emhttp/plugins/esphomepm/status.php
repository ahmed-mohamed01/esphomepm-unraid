<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set headers to prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Load configuration
$config_file = "/boot/config/plugins/esphomepm/esphomepm.cfg";
$esphomepm_cfg = file_exists($config_file) ? parse_ini_file($config_file) : [];

// Get configuration values with defaults
$esphomepm_device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : "";
$esphomepm_costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : "0.27";
$esphomepm_costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : "GBP";

// Debug information
$debug = [];
$debug['config_file_exists'] = file_exists($config_file);
$debug['config_file_path'] = $config_file;
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

$baseUrl = "http://" . $esphomepm_device_ip;
$debug['base_url'] = $baseUrl;

// Initialize curl
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_TIMEOUT => 5,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => true, // Follow redirects
    CURLOPT_MAXREDIRS => 3,         // Maximum number of redirects to follow
    CURLOPT_NOBODY => false,        // We want the body
    CURLOPT_HEADER => false         // Don't need headers
]);

function getSensorValue($ch, $baseUrl, $sensor, &$debug) {
    $url = $baseUrl . "/sensor/" . $sensor;
    curl_setopt($ch, CURLOPT_URL, $url);
    
    $debug[$sensor . '_url'] = $url;
    $response = curl_exec($ch);
    
    $debug[$sensor . '_curl_error'] = curl_error($ch);
    $debug[$sensor . '_curl_errno'] = curl_errno($ch);
    $debug[$sensor . '_http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log("ESPHome API Error for $sensor: " . curl_error($ch));
        return 0;
    }
    
    $debug[$sensor . '_response'] = substr($response, 0, 100); // Limit debug output
    
    $data = json_decode($response, true);
    $debug[$sensor . '_parsed'] = is_array($data);
    
    return isset($data['value']) ? floatval($data['value']) : 0;
}

// Get sensor values
$power = getSensorValue($ch, $baseUrl, "power", $debug);
$daily_energy = getSensorValue($ch, $baseUrl, "daily_energy", $debug);
$voltage = getSensorValue($ch, $baseUrl, "voltage", $debug);
$current = getSensorValue($ch, $baseUrl, "current", $debug);

curl_close($ch);

$json = array(
    'Total' => $daily_energy,
    'Power' => $power,
    'Voltage' => $voltage,
    'Current' => $current,
    'Costs_Price' => $esphomepm_costs_price,
    'Costs_Unit' => $esphomepm_costs_unit,
    'Debug' => $debug
);

header('Content-Type: application/json');
echo json_encode($json);
?>