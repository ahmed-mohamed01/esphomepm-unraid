<?php
require_once __DIR__ . '/include/bootstrap.php'; // Use bootstrap for all utilities

// Initialize script, load config. True for $jsonResponse means esphomepm_init_script will call esphomepm_set_json_headers.
$config = esphomepm_init_script('status', true);

// Handle graph point request specifically
if (isset($_GET['graph_point']) && $_GET['graph_point'] === 'true') {
    $device_ip = $config['DEVICE_IP'] ?? "";
    if (empty($device_ip)) {
        // Headers already set by esphomepm_init_script, but for clarity, ensure this specific path also guarantees JSON response type.
        // No need to call esphomepm_set_json_headers() again if init_script handled it.
        echo json_encode(['power' => 0, 'error' => 'ESPHome Device IP missing']);
        exit;
    }
    $power_sensor_path = $config['POWER_SENSOR_PATH'] ?? 'power';
    // Use esphomepm_fetch_sensor_data for consistency and full error/value pair
    $power_data = esphomepm_fetch_sensor_data($device_ip, $power_sensor_path, 1, true); 
    echo json_encode(['power' => $power_data['value'] ?? 0, 'error' => $power_data['error']]);
    exit;
}

// Main data request: all logic is now consolidated in esphomepm_build_summary
// This function (from ui_utils.php) handles device IP checks, live data fetching, 
// historical data loading, and error aggregation.
$summary_data = esphomepm_build_summary($config);

// Output the summary. Headers were set by esphomepm_init_script.
echo json_encode($summary_data);
exit;
?>