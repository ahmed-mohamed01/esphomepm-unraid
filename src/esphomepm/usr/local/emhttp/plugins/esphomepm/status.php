<?php
$esphomepm_cfg = parse_ini_file( "/boot/config/plugins/esphomepm/esphomepm.cfg" );
$esphomepm_device_ip = isset($esphomepm_cfg['DEVICE_IP']) ? $esphomepm_cfg['DEVICE_IP'] : "";
$esphomepm_costs_price = isset($esphomepm_cfg['COSTS_PRICE']) ? $esphomepm_cfg['COSTS_PRICE'] : "0.27";
$esphomepm_costs_unit = isset($esphomepm_cfg['COSTS_UNIT']) ? $esphomepm_cfg['COSTS_UNIT'] : "GBP";

if ($esphomepm_device_ip == "") {
	die("ESPHome Device IP missing!");
}

$Url = "http://" . $esphomepm_device_ip;

$power = json_decode(file_get_contents($Url . "/sensor/power"), true);
$energy = json_decode(file_get_contents($Url . "/sensor/daily_energy"), true);  // Changed from energy to daily_energy
$voltage = json_decode(file_get_contents($Url . "/sensor/voltage"), true);
$current = json_decode(file_get_contents($Url . "/sensor/current"), true);

$json = array(
		'Total' => $energy['state'],
		'Power' => $power['state'],
		'Voltage' => $voltage['state'],
		'Current' => $current['state'],
		'Factor' => $power_factor['state'],
		'Costs_Price' => $esphomepm_costs_price,
		'Costs_Unit' => $esphomepm_costs_unit
	);

header('Content-Type: application/json');
echo json_encode($json);
?>