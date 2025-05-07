<?php
$esphomepm_cfg = parse_ini_file( "/boot/config/plugins/esphomepm/esphomepm.cfg" );
$esphomepm_device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : "";
$esphomepm_costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : "0.27";
$esphomepm_costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : "GBP";

if ($esphomepm_device_ip == "") {
	header('Content-Type: application/json');
	echo json_encode(['error' => 'ESPHome Device IP missing']);
	exit;
}

$Url = "http://" . $esphomepm_device_ip;

// Function to extract numeric value from state string
function extractNumericValue($state) {
	return floatval(preg_replace('/[^0-9.]/', '', $state));
}

// Get data from each sensor
$power = json_decode(file_get_contents($Url . "/sensor/power"), true);
$energy = json_decode(file_get_contents($Url . "/sensor/daily_energy"), true);
$voltage = json_decode(file_get_contents($Url . "/sensor/voltage"), true);
$current = json_decode(file_get_contents($Url . "/sensor/current"), true);

$json = array(
		'Total' => $energy ? $energy['value'] : 0,
		'Power' => $power ? $power['value'] : 0,
		'Voltage' => $voltage ? $voltage['value'] : 0,
		'Current' => $current ? $current['value'] : 0,
		'Costs_Price' => $esphomepm_costs_price,
		'Costs_Unit' => $esphomepm_costs_unit
	);

header('Content-Type: application/json');
echo json_encode($json);
?>