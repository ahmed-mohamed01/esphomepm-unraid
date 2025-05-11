# ESPHome Power Monitor for UnRaid

This UnRaid plugin enables power monitoring for your server using ESPHome-powered smart plugs or power monitoring devices. It is derived from the excellent [TasmotaPM UnRaid plugin](https://github.com/Flippo24/tasmotapm-unraid) but modified to work with ESPHome's native API.

## Introduction

ESPHome Power Monitor allows you to track your UnRaid server's power consumption using any ESPHome-compatible device with power monitoring capabilities. The plugin integrates seamlessly with UnRaid's interface and provides real-time power monitoring, energy consumption tracking, and cost calculations.

## Requirements

1. An ESPHome device with power monitoring sensors (I am using a LocalBytes plug flashed with ESPHome)
2. The following sensors must be configured in your ESPHome device:
   - `power` - Current power consumption in watts
   - `voltage` - Current voltage
   - `current` - Current amperage
   - `total_daily_energy` - Today's energy consumption in kWh

## ESPHome Configuration Example

Sample ESPHome configuration is located in the `sample` directory of this repository.

## Enable the web server for API access

```yaml
api:
  password: "your_secure_password"  # Optional but recommended

web_server:
  port: 80
```

## Installation

1. In UnRaid, go to Plugins > Install Plugin
2. Enter the following URL:
   ```
   https://raw.githubusercontent.com/ahmed-mohamed01/esphomepm-unraid/main/esphomepm.plg
   ```

## Configuration

1. Go to Settings > ESPHome Power Monitor
2. Enter your ESPHome device's IP address`
4. Set the UI refresh rate (default: 2000ms)
5. Configure power cost settings: Defaults to GBP and out of contract rate in UK in May 2025 (27p/kWh)
6. Click Apply

## Security Note

Make sure your ESPHome device is properly secured, especially if it's accessible from outside your local network. It's recommended to:
1. Enable API password protection
2. Use encryption for the API if possible
3. Keep your ESPHome device's firmware up to date

## Troubleshooting

If you're not seeing data:
1. Verify your ESPHome device is accessible at the configured IP address
2. Check that all required sensors are properly configured in your ESPHome configuration
4. Check UnRaid's` system log for any error messages

## Credits

This plugin is derived from the [TasmotaPM UnRaid plugin](https://github.com/Flippo24/tasmotapm-unraid) by Flippo24. Modified to work with ESPHome devices instead of Tasmota.

## License

This project is licensed under the MIT License - see the LICENSE.txt file for details.
