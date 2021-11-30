<?php

namespace mac\iot\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Utils, \Shipard\Utils\Json, \Shipard\Utils\Str;


/**
 * Class ZigbeeInfoAnalyzer
 */
class ZigbeeInfoAnalyzer extends Utility
{
	var ?array $data = NULL;
	var ?\mac\iot\libs\IotDevicesUtils $iotDevicesUtils = NULL;
	var \mac\iot\TableDevices $tableIotDevices;

	public function setData(array $data)
	{
		$this->data = $data;
	}

	protected function doEndDevice($data, $addToDatabase = TRUE)
	{
		/*
		{
        "ieee_address":"0x90fd9ffffe6494fc",
        "type":"Router",
        "network_address":57440,
        "supported":true,
        "friendly_name":"my_bulb",
        "endpoints":{"1":{"bindings":[],"configured_reportings":[],"clusters":{"input":["genOnOff","genBasic","genLevelCtrl"],"output":["genOta"]}}},
        "definition":{
            "model":"LED1624G9",
            "vendor":"IKEA",
            "description":"TRADFRI LED bulb E14/E26/E27 600 lumen, dimmable, color, opal white",
            "options": [...], // see exposes/options below
            "exposes": [...]  // see exposes/options below
        },
        "power_source":"Mains (single phase)",
        "software_build_id":"1.3.009",
        "model_id":"TRADFRI bulb E27 CWS opal 600lm",
        "scenes": [],
        "date_code":"20180410",
        "interviewing":false,
        "interview_completed":true
    },
		*/

		if (!isset($data['definition']))
		{
			error_log("#### definition MISSING");
			return FALSE;
		}
		

		if (!isset($data['definition']['vendor']))
		{
			error_log("#### VENDOR MISSING");
			return FALSE;
		}

		$vendor = $this->iotDevicesUtils->searchVendor('zigbee', $data['definition']['vendor']);
		if (!$vendor)
		{
			error_log("#### Vendor not found: `{$data['definition']['vendor']}`");
			return FALSE;
		}	
		$model = 	$this->iotDevicesUtils->searchModel ('zigbee', $vendor['id'], $data['definition']['model'] ?? '', $data['model_id'] ?? '');
		if (!$model)
		{
			error_log("#### Model not found: `{$data['model']}`");
			return FALSE;
		}	
		if ($addToDatabase)
		{
			$iotDeviceNdx = 0;

			$hwId = $data['ieee_address']	?? '';
			if ($hwId !== '')
			{
				$exist = $this->db()->query('SELECT * FROM [mac_iot_devices] WHERE hwId = %s', $hwId)->fetch();
				if ($exist)
				{ // UPDATE
					$iotDeviceNdx = $exist['ndx'];
					$update = [
						'friendlyId' => Str::upToLen($data['friendly_name'] ?? '', 60),
						'deviceVendor' => Str::upToLen($vendor['id'] ?? '', 20),
						'deviceModel' => Str::upToLen($model['id'] ?? '', 40),
					];
					$this->db()->query('UPDATE [mac_iot_devices] SET ', $update, ' WHERE [ndx] = %i', $iotDeviceNdx);
					$this->tableIotDevices->docsLog($iotDeviceNdx);
				}
				else
				{
					$newItem = [
						'fullName' => Str::upToLen($data['definition']['description'] ?? '', 120),
						'friendlyId' => Str::upToLen($data['friendly_name'] ?? '', 60),
						'hwId' => Str::upToLen($data['ieee_address'] ?? '', 24),
						'lan' => 0,
						'deviceType' => 'zigbee',
						'deviceVendor' => Str::upToLen($vendor['id'] ?? '', 20),
						'deviceModel' => Str::upToLen($model['id'] ?? '', 40),
						'docState' => 1000, 'docStateMain' => 0, 
					];

					//error_log("--NEW: ".json_encode($newItem));
					$iotDeviceNdx = $this->tableIotDevices->dbInsertRec($newItem);
					$this->tableIotDevices->docsLog($iotDeviceNdx);
				}
			}
			else
			{
				error_log("#### hwId not found: `{$data['ieeeAddr']}`");
			}

			if ($iotDeviceNdx)
			{
				$iotDeviceCfg = $this->iotDevicesUtils->getIotDeviceCfg($iotDeviceNdx);
				if ($iotDeviceCfg)
				{
					$dd = $data;
					if (isset($dd['endpoints']))
						unset($dd['endpoints']);
					if (isset($dd['scenes']))
						unset($dd['scenes']);

					$newDeviceCfg = ['deviceInfoData' => Json::lint($dd), 'deviceInfoTimestamp' => new \DateTime()];
					$newDeviceCfg['deviceInfoVer'] = sha1($newDeviceCfg['deviceInfoData']);
					if ($newDeviceCfg['deviceInfoVer'] !== $iotDeviceCfg['deviceInfoVer'])
						$this->iotDevicesUtils->setIotDeviceCfg($iotDeviceNdx, $newDeviceCfg);


					$drd = $this->tableIotDevices->loadItem($iotDeviceNdx);
					$this->tableIotDevices->checkAfterSave2($drd);
				}
			}
		}

		return TRUE;	
	}

	protected function analyze()
	{
		if (!$this->data)
		{
			error_log("!!!FAIL: ".json_encode($this->data));
			return 'FAIL';
		}

		if ($this->data['type'] === 'zigbee-devices-list')
		{
			foreach ($this->data['data'] as $msg)
			{
				if (isset($msg['type']) && $msg['type'] === 'Coordinator')
					continue;
				
				$this->doEndDevice($msg);
			}
		}	

		return 'OK';
	}

	public function run()
	{
		$this->iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());
		$this->tableIotDevices = new \mac\iot\TableDevices($this->app());
		return $this->analyze();
	}
}


/*

[{
	"definition": null,
	"endpoints": {
		"1": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"10": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"11": {
			"bindings": [],
			"clusters": {
				"input": ["ssIasAce"],
				"output": ["ssIasZone", "ssIasWd"]
			},
			"configured_reportings": [],
			"scenes": []
		},
		"110": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"12": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"13": {
			"bindings": [],
			"clusters": {
				"input": ["genOta"],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"2": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"242": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"3": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"4": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"47": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"5": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"6": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		},
		"8": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": []
			},
			"configured_reportings": [],
			"scenes": []
		}
	},
	"friendly_name": "Coordinator",
	"ieee_address": "0x00124b002393334b",
	"interview_completed": true,
	"interviewing": false,
	"network_address": 0,
	"supported": false,
	"type": "Coordinator"
}, {
	"date_code": "20210422",
	"definition": {
		"description": "Hue white A60 bulb E27 bluetooth",
		"exposes": [{
			"features": [{
				"access": 7,
				"description": "On/off state of this light",
				"name": "state",
				"property": "state",
				"type": "binary",
				"value_off": "OFF",
				"value_on": "ON",
				"value_toggle": "TOGGLE"
			}, {
				"access": 7,
				"description": "Brightness of this light",
				"name": "brightness",
				"property": "brightness",
				"type": "numeric",
				"value_max": 254,
				"value_min": 0
			}],
			"type": "light"
		}, {
			"access": 2,
			"description": "Triggers an effect on the light (e.g. make light blink for a few seconds)",
			"name": "effect",
			"property": "effect",
			"type": "enum",
			"values": ["blink", "breathe", "okay", "channel_change", "finish_effect", "stop_effect"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "929001821618",
		"options": [{
			"access": 2,
			"description": "Controls the transition time (in seconds) of on/off, brightness, color temperature (if applicable) and color (if applicable) changes. Defaults to `0` (no transition).",
			"name": "transition",
			"property": "transition",
			"type": "numeric",
			"value_min": 0
		}],
		"supports_ota": true,
		"vendor": "Philips"
	},
	"endpoints": {
		"11": {
			"bindings": [],
			"clusters": {
				"input": ["genBasic", "genIdentify", "genGroups", "genScenes", "genOnOff", "genLevelCtrl", "touchlink", "develcoSpecificAirQuality", "manuSpecificSamsungAccelerometer"],
				"output": ["genOta"]
			},
			"configured_reportings": [],
			"scenes": []
		},
		"242": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": ["greenPower"]
			},
			"configured_reportings": [],
			"scenes": []
		}
	},
	"friendly_name": "light-wk-ph1",
	"ieee_address": "0x00178801099e2589",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "Signify Netherlands B.V.",
	"model_id": "LWA011",
	"network_address": 63745,
	"power_source": "Mains (single phase)",
	"software_build_id": "1.88.2",
	"supported": true,
	"type": "Router"
}, {
	"date_code": "20210422",
	"definition": {
		"description": "Hue white A60 bulb E27 bluetooth",
		"exposes": [{
			"features": [{
				"access": 7,
				"description": "On/off state of this light",
				"name": "state",
				"property": "state",
				"type": "binary",
				"value_off": "OFF",
				"value_on": "ON",
				"value_toggle": "TOGGLE"
			}, {
				"access": 7,
				"description": "Brightness of this light",
				"name": "brightness",
				"property": "brightness",
				"type": "numeric",
				"value_max": 254,
				"value_min": 0
			}],
			"type": "light"
		}, {
			"access": 2,
			"description": "Triggers an effect on the light (e.g. make light blink for a few seconds)",
			"name": "effect",
			"property": "effect",
			"type": "enum",
			"values": ["blink", "breathe", "okay", "channel_change", "finish_effect", "stop_effect"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "929001821618",
		"options": [{
			"access": 2,
			"description": "Controls the transition time (in seconds) of on/off, brightness, color temperature (if applicable) and color (if applicable) changes. Defaults to `0` (no transition).",
			"name": "transition",
			"property": "transition",
			"type": "numeric",
			"value_min": 0
		}],
		"supports_ota": true,
		"vendor": "Philips"
	},
	"endpoints": {
		"11": {
			"bindings": [],
			"clusters": {
				"input": ["genBasic", "genIdentify", "genGroups", "genScenes", "genOnOff", "genLevelCtrl", "touchlink", "manuSpecificSamsungAccelerometer"],
				"output": ["genOta"]
			},
			"configured_reportings": [],
			"scenes": []
		},
		"242": {
			"bindings": [],
			"clusters": {
				"input": [],
				"output": ["greenPower"]
			},
			"configured_reportings": [],
			"scenes": []
		}
	},
	"friendly_name": "light-3d",
	"ieee_address": "0x00178801099e7e5c",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "Philips",
	"model_id": "LWA011",
	"network_address": 17977,
	"power_source": "Mains (single phase)",
	"software_build_id": "1.88.2",
	"supported": true,
	"type": "Router"
}, {
	"date_code": "20200708",
	"definition": {
		"description": "STYRBAR remote control N2",
		"exposes": [{
			"access": 1,
			"description": "Remaining battery in %",
			"name": "battery",
			"property": "battery",
			"type": "numeric",
			"unit": "%",
			"value_max": 100,
			"value_min": 0
		}, {
			"access": 1,
			"description": "Triggered action (e.g. a button click)",
			"name": "action",
			"property": "action",
			"type": "enum",
			"values": ["on", "off", "brightness_move_up", "brightness_move_down", "brightness_stop", "arrow_left_click", "arrow_right_click", "arrow_left_hold", "arrow_right_hold", "arrow_left_release", "arrow_right_release"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "E2001/E2002",
		"options": [{
			"description": "Simulate a brightness value. If this device provides a brightness_move_up or brightness_move_down action it is possible to specify the update interval and delta.",
			"features": [{
				"access": 2,
				"description": "Delta per interval, 20 by default",
				"name": "delta",
				"property": "delta",
				"type": "numeric",
				"value_min": 0
			}, {
				"access": 2,
				"description": "Interval duration",
				"name": "interval",
				"property": "interval",
				"type": "numeric",
				"unit": "ms",
				"value_min": 0
			}],
			"name": "simulated_brightness",
			"property": "simulated_brightness",
			"type": "composite"
		}, {
			"access": 2,
			"description": "Set to false to disable the legacy integration (highly recommended), will change structure of the published payload (default true).",
			"name": "legacy",
			"property": "legacy",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [{
				"cluster": "genOnOff",
				"target": {
					"id": 901,
					"type": "group"
				}
			}, {
				"cluster": "genPowerCfg",
				"target": {
					"endpoint": 1,
					"ieee_address": "0x00124b002393334b",
					"type": "endpoint"
				}
			}],
			"clusters": {
				"input": ["genBasic", "genPowerCfg", "genIdentify", "genPollCtrl", "touchlink"],
				"output": ["genIdentify", "genOnOff", "genLevelCtrl", "genOta", "touchlink"]
			},
			"configured_reportings": [{
				"attribute": "batteryPercentageRemaining",
				"cluster": "genPowerCfg",
				"maximum_report_interval": 62000,
				"minimum_report_interval": 3600,
				"reportable_change": 0
			}],
			"scenes": []
		}
	},
	"friendly_name": "prepinac",
	"ieee_address": "0x842e14fffe8df496",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "Remote Control N2",
	"network_address": 48834,
	"power_source": "Battery",
	"software_build_id": "1.0.024",
	"supported": true,
	"type": "EndDevice"
}, {
	"date_code": "20201111",
	"definition": {
		"description": "TRADFRI LED globe-bulb E26/E27 450/470 lumen, dimmable, white spectrum, opal white",
		"exposes": [{
			"features": [{
				"access": 7,
				"description": "On/off state of this light",
				"name": "state",
				"property": "state",
				"type": "binary",
				"value_off": "OFF",
				"value_on": "ON",
				"value_toggle": "TOGGLE"
			}, {
				"access": 7,
				"description": "Brightness of this light",
				"name": "brightness",
				"property": "brightness",
				"type": "numeric",
				"value_max": 254,
				"value_min": 0
			}, {
				"access": 7,
				"description": "Color temperature of this light",
				"name": "color_temp",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}],
				"property": "color_temp",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}, {
				"access": 7,
				"description": "Color temperature after cold power on of this light",
				"name": "color_temp_startup",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}, {
					"description": "Restore previous color_temp on cold power on",
					"name": "previous",
					"value": 65535
				}],
				"property": "color_temp_startup",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}],
			"type": "light"
		}, {
			"access": 2,
			"description": "Triggers an effect on the light (e.g. make light blink for a few seconds)",
			"name": "effect",
			"property": "effect",
			"type": "enum",
			"values": ["blink", "breathe", "okay", "channel_change", "finish_effect", "stop_effect"]
		}, {
			"access": 7,
			"description": "Controls the behavior when the device is powered on",
			"name": "power_on_behavior",
			"property": "power_on_behavior",
			"type": "enum",
			"values": ["off", "previous", "on"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "LED1936G5",
		"options": [{
			"access": 2,
			"description": "Controls the transition time (in seconds) of on/off, brightness, color temperature (if applicable) and color (if applicable) changes. Defaults to `0` (no transition).",
			"name": "transition",
			"property": "transition",
			"type": "numeric",
			"value_min": 0
		}, {
			"access": 2,
			"description": "When enabled colors will be synced, e.g. if the light supports both color x/y and color temperature a conversion from color x/y to color temperature will be done when setting the x/y color (default true).",
			"name": "color_sync",
			"property": "color_sync",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [],
			"clusters": {
				"input": ["genBasic", "genIdentify", "genGroups", "genScenes", "genOnOff", "genLevelCtrl", "lightingColorCtrl", "touchlink"],
				"output": ["genOta"]
			},
			"configured_reportings": [],
			"scenes": []
		},
		"242": {
			"bindings": [],
			"clusters": {
				"input": ["greenPower"],
				"output": ["greenPower"]
			},
			"configured_reportings": [],
			"scenes": []
		}
	},
	"friendly_name": "KAV-P1",
	"ieee_address": "0x680ae2fffe5944e0",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "TRADFRIbulbG125E27WSopal470lm",
	"network_address": 2118,
	"power_source": "Mains (single phase)",
	"software_build_id": "1.0.012",
	"supported": true,
	"type": "Router"
}, {
	"date_code": "20190308",
	"definition": {
		"description": "TRADFRI motion sensor",
		"exposes": [{
			"access": 1,
			"description": "Remaining battery in %",
			"name": "battery",
			"property": "battery",
			"type": "numeric",
			"unit": "%",
			"value_max": 100,
			"value_min": 0
		}, {
			"access": 1,
			"description": "Indicates whether the device detected occupancy",
			"name": "occupancy",
			"property": "occupancy",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}, {
			"access": 1,
			"name": "requested_brightness_level",
			"property": "requested_brightness_level",
			"type": "numeric",
			"value_max": 254,
			"value_min": 76
		}, {
			"access": 1,
			"name": "requested_brightness_percent",
			"property": "requested_brightness_percent",
			"type": "numeric",
			"value_max": 100,
			"value_min": 30
		}, {
			"access": 1,
			"description": "Indicates whether the device detected bright light (works only in night mode)",
			"name": "illuminance_above_threshold",
			"property": "illuminance_above_threshold",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "E1525/E1745",
		"options": [{
			"access": 2,
			"description": "Time in seconds after which occupancy is cleared after detecting it (default 90 seconds).",
			"name": "occupancy_timeout",
			"property": "occupancy_timeout",
			"type": "numeric",
			"value_min": 0
		}, {
			"access": 2,
			"description": "Set to false to also send messages when illuminance is above threshold in night mode (default true).",
			"name": "illuminance_below_threshold_check",
			"property": "illuminance_below_threshold_check",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [{
				"cluster": "genPowerCfg",
				"target": {
					"endpoint": 1,
					"ieee_address": "0x00124b002393334b",
					"type": "endpoint"
				}
			}],
			"clusters": {
				"input": ["genBasic", "genPowerCfg", "genIdentify", "genAlarms", "genPollCtrl", "touchlink"],
				"output": ["genIdentify", "genGroups", "genOnOff", "genLevelCtrl", "genOta", "touchlink"]
			},
			"configured_reportings": [{
				"attribute": "batteryPercentageRemaining",
				"cluster": "genPowerCfg",
				"maximum_report_interval": 62000,
				"minimum_report_interval": 3600,
				"reportable_change": 0
			}],
			"scenes": []
		}
	},
	"friendly_name": "0xb4e3f9fffe7528d2",
	"ieee_address": "0xb4e3f9fffe7528d2",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "TRADFRI motion sensor",
	"network_address": 40142,
	"power_source": "Battery",
	"software_build_id": "2.0.022",
	"supported": true,
	"type": "EndDevice"
}, {
	"date_code": "20190710",
	"definition": {
		"description": "SYMFONISK sound controller",
		"exposes": [{
			"access": 1,
			"description": "Remaining battery in %",
			"name": "battery",
			"property": "battery",
			"type": "numeric",
			"unit": "%",
			"value_max": 100,
			"value_min": 0
		}, {
			"access": 1,
			"description": "Triggered action (e.g. a button click)",
			"name": "action",
			"property": "action",
			"type": "enum",
			"values": ["brightness_move_up", "brightness_move_down", "brightness_stop", "toggle", "brightness_step_up", "brightness_step_down"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "E1744",
		"options": [{
			"access": 2,
			"description": "Set to false to disable the legacy integration (highly recommended), will change structure of the published payload (default true).",
			"name": "legacy",
			"property": "legacy",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [{
				"cluster": "genLevelCtrl",
				"target": {
					"endpoint": 1,
					"ieee_address": "0x00124b002393334b",
					"type": "endpoint"
				}
			}, {
				"cluster": "genPowerCfg",
				"target": {
					"endpoint": 1,
					"ieee_address": "0x00124b002393334b",
					"type": "endpoint"
				}
			}],
			"clusters": {
				"input": ["genBasic", "genPowerCfg", "genIdentify", "genPollCtrl", "touchlink"],
				"output": ["genIdentify", "genGroups", "genOnOff", "genLevelCtrl", "genOta", "touchlink"]
			},
			"configured_reportings": [{
				"attribute": "batteryPercentageRemaining",
				"cluster": "genPowerCfg",
				"maximum_report_interval": 62000,
				"minimum_report_interval": 3600,
				"reportable_change": 0
			}],
			"scenes": []
		}
	},
	"friendly_name": "light-wk-dimmer",
	"ieee_address": "0x0c4314fffeae4f86",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "SYMFONISK Sound Controller",
	"network_address": 37678,
	"power_source": "Battery",
	"software_build_id": "2.1.024",
	"supported": true,
	"type": "EndDevice"
}, {
	"date_code": "20201111",
	"definition": {
		"description": "TRADFRI LED globe-bulb E26/E27 450/470 lumen, dimmable, white spectrum, opal white",
		"exposes": [{
			"features": [{
				"access": 7,
				"description": "On/off state of this light",
				"name": "state",
				"property": "state",
				"type": "binary",
				"value_off": "OFF",
				"value_on": "ON",
				"value_toggle": "TOGGLE"
			}, {
				"access": 7,
				"description": "Brightness of this light",
				"name": "brightness",
				"property": "brightness",
				"type": "numeric",
				"value_max": 254,
				"value_min": 0
			}, {
				"access": 7,
				"description": "Color temperature of this light",
				"name": "color_temp",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}],
				"property": "color_temp",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}, {
				"access": 7,
				"description": "Color temperature after cold power on of this light",
				"name": "color_temp_startup",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}, {
					"description": "Restore previous color_temp on cold power on",
					"name": "previous",
					"value": 65535
				}],
				"property": "color_temp_startup",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}],
			"type": "light"
		}, {
			"access": 2,
			"description": "Triggers an effect on the light (e.g. make light blink for a few seconds)",
			"name": "effect",
			"property": "effect",
			"type": "enum",
			"values": ["blink", "breathe", "okay", "channel_change", "finish_effect", "stop_effect"]
		}, {
			"access": 7,
			"description": "Controls the behavior when the device is powered on",
			"name": "power_on_behavior",
			"property": "power_on_behavior",
			"type": "enum",
			"values": ["off", "previous", "on"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "LED1936G5",
		"options": [{
			"access": 2,
			"description": "Controls the transition time (in seconds) of on/off, brightness, color temperature (if applicable) and color (if applicable) changes. Defaults to `0` (no transition).",
			"name": "transition",
			"property": "transition",
			"type": "numeric",
			"value_min": 0
		}, {
			"access": 2,
			"description": "When enabled colors will be synced, e.g. if the light supports both color x/y and color temperature a conversion from color x/y to color temperature will be done when setting the x/y color (default true).",
			"name": "color_sync",
			"property": "color_sync",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [],
			"clusters": {
				"input": ["genBasic", "genIdentify", "genGroups", "genScenes", "genOnOff", "genLevelCtrl", "lightingColorCtrl", "touchlink"],
				"output": ["genOta"]
			},
			"configured_reportings": [],
			"scenes": []
		},
		"242": {
			"bindings": [],
			"clusters": {
				"input": ["greenPower"],
				"output": ["greenPower"]
			},
			"configured_reportings": [],
			"scenes": []
		}
	},
	"friendly_name": "KAV-S2",
	"ieee_address": "0x680ae2fffe575a9c",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "TRADFRIbulbG125E27WSopal470lm",
	"network_address": 8280,
	"power_source": "Mains (single phase)",
	"software_build_id": "1.0.012",
	"supported": true,
	"type": "Router"
}, {
	"date_code": "20201111",
	"definition": {
		"description": "TRADFRI LED globe-bulb E26/E27 450/470 lumen, dimmable, white spectrum, opal white",
		"exposes": [{
			"features": [{
				"access": 7,
				"description": "On/off state of this light",
				"name": "state",
				"property": "state",
				"type": "binary",
				"value_off": "OFF",
				"value_on": "ON",
				"value_toggle": "TOGGLE"
			}, {
				"access": 7,
				"description": "Brightness of this light",
				"name": "brightness",
				"property": "brightness",
				"type": "numeric",
				"value_max": 254,
				"value_min": 0
			}, {
				"access": 7,
				"description": "Color temperature of this light",
				"name": "color_temp",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}],
				"property": "color_temp",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}, {
				"access": 7,
				"description": "Color temperature after cold power on of this light",
				"name": "color_temp_startup",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}, {
					"description": "Restore previous color_temp on cold power on",
					"name": "previous",
					"value": 65535
				}],
				"property": "color_temp_startup",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}],
			"type": "light"
		}, {
			"access": 2,
			"description": "Triggers an effect on the light (e.g. make light blink for a few seconds)",
			"name": "effect",
			"property": "effect",
			"type": "enum",
			"values": ["blink", "breathe", "okay", "channel_change", "finish_effect", "stop_effect"]
		}, {
			"access": 7,
			"description": "Controls the behavior when the device is powered on",
			"name": "power_on_behavior",
			"property": "power_on_behavior",
			"type": "enum",
			"values": ["off", "previous", "on"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "LED1936G5",
		"options": [{
			"access": 2,
			"description": "Controls the transition time (in seconds) of on/off, brightness, color temperature (if applicable) and color (if applicable) changes. Defaults to `0` (no transition).",
			"name": "transition",
			"property": "transition",
			"type": "numeric",
			"value_min": 0
		}, {
			"access": 2,
			"description": "When enabled colors will be synced, e.g. if the light supports both color x/y and color temperature a conversion from color x/y to color temperature will be done when setting the x/y color (default true).",
			"name": "color_sync",
			"property": "color_sync",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [],
			"clusters": {
				"input": ["genBasic", "genIdentify", "genGroups", "genScenes", "genOnOff", "genLevelCtrl", "lightingColorCtrl", "touchlink"],
				"output": ["genOta"]
			},
			"configured_reportings": [],
			"scenes": []
		},
		"242": {
			"bindings": [],
			"clusters": {
				"input": ["greenPower"],
				"output": ["greenPower"]
			},
			"configured_reportings": [],
			"scenes": []
		}
	},
	"friendly_name": "KAV-P2",
	"ieee_address": "0x680ae2fffe47aacb",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "TRADFRIbulbG125E27WSopal470lm",
	"network_address": 64534,
	"power_source": "Mains (single phase)",
	"software_build_id": "1.0.012",
	"supported": true,
	"type": "Router"
}, {
	"date_code": "20201111",
	"definition": {
		"description": "TRADFRI LED globe-bulb E26/E27 450/470 lumen, dimmable, white spectrum, opal white",
		"exposes": [{
			"features": [{
				"access": 7,
				"description": "On/off state of this light",
				"name": "state",
				"property": "state",
				"type": "binary",
				"value_off": "OFF",
				"value_on": "ON",
				"value_toggle": "TOGGLE"
			}, {
				"access": 7,
				"description": "Brightness of this light",
				"name": "brightness",
				"property": "brightness",
				"type": "numeric",
				"value_max": 254,
				"value_min": 0
			}, {
				"access": 7,
				"description": "Color temperature of this light",
				"name": "color_temp",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}],
				"property": "color_temp",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}, {
				"access": 7,
				"description": "Color temperature after cold power on of this light",
				"name": "color_temp_startup",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}, {
					"description": "Restore previous color_temp on cold power on",
					"name": "previous",
					"value": 65535
				}],
				"property": "color_temp_startup",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}],
			"type": "light"
		}, {
			"access": 2,
			"description": "Triggers an effect on the light (e.g. make light blink for a few seconds)",
			"name": "effect",
			"property": "effect",
			"type": "enum",
			"values": ["blink", "breathe", "okay", "channel_change", "finish_effect", "stop_effect"]
		}, {
			"access": 7,
			"description": "Controls the behavior when the device is powered on",
			"name": "power_on_behavior",
			"property": "power_on_behavior",
			"type": "enum",
			"values": ["off", "previous", "on"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "LED1936G5",
		"options": [{
			"access": 2,
			"description": "Controls the transition time (in seconds) of on/off, brightness, color temperature (if applicable) and color (if applicable) changes. Defaults to `0` (no transition).",
			"name": "transition",
			"property": "transition",
			"type": "numeric",
			"value_min": 0
		}, {
			"access": 2,
			"description": "When enabled colors will be synced, e.g. if the light supports both color x/y and color temperature a conversion from color x/y to color temperature will be done when setting the x/y color (default true).",
			"name": "color_sync",
			"property": "color_sync",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [],
			"clusters": {
				"input": ["genBasic", "genIdentify", "genGroups", "genScenes", "genOnOff", "genLevelCtrl", "lightingColorCtrl", "touchlink"],
				"output": ["genOta"]
			},
			"configured_reportings": [],
			"scenes": []
		},
		"242": {
			"bindings": [],
			"clusters": {
				"input": ["greenPower"],
				"output": ["greenPower"]
			},
			"configured_reportings": [],
			"scenes": []
		}
	},
	"friendly_name": "KAV-L1",
	"ieee_address": "0x680ae2fffe47e81e",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "TRADFRIbulbG125E27WSopal470lm",
	"network_address": 24764,
	"power_source": "Mains (single phase)",
	"software_build_id": "1.0.012",
	"supported": true,
	"type": "Router"
}, {
	"date_code": "20201111",
	"definition": {
		"description": "TRADFRI LED globe-bulb E26/E27 450/470 lumen, dimmable, white spectrum, opal white",
		"exposes": [{
			"features": [{
				"access": 7,
				"description": "On/off state of this light",
				"name": "state",
				"property": "state",
				"type": "binary",
				"value_off": "OFF",
				"value_on": "ON",
				"value_toggle": "TOGGLE"
			}, {
				"access": 7,
				"description": "Brightness of this light",
				"name": "brightness",
				"property": "brightness",
				"type": "numeric",
				"value_max": 254,
				"value_min": 0
			}, {
				"access": 7,
				"description": "Color temperature of this light",
				"name": "color_temp",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}],
				"property": "color_temp",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}, {
				"access": 7,
				"description": "Color temperature after cold power on of this light",
				"name": "color_temp_startup",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}, {
					"description": "Restore previous color_temp on cold power on",
					"name": "previous",
					"value": 65535
				}],
				"property": "color_temp_startup",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}],
			"type": "light"
		}, {
			"access": 2,
			"description": "Triggers an effect on the light (e.g. make light blink for a few seconds)",
			"name": "effect",
			"property": "effect",
			"type": "enum",
			"values": ["blink", "breathe", "okay", "channel_change", "finish_effect", "stop_effect"]
		}, {
			"access": 7,
			"description": "Controls the behavior when the device is powered on",
			"name": "power_on_behavior",
			"property": "power_on_behavior",
			"type": "enum",
			"values": ["off", "previous", "on"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "LED1936G5",
		"options": [{
			"access": 2,
			"description": "Controls the transition time (in seconds) of on/off, brightness, color temperature (if applicable) and color (if applicable) changes. Defaults to `0` (no transition).",
			"name": "transition",
			"property": "transition",
			"type": "numeric",
			"value_min": 0
		}, {
			"access": 2,
			"description": "When enabled colors will be synced, e.g. if the light supports both color x/y and color temperature a conversion from color x/y to color temperature will be done when setting the x/y color (default true).",
			"name": "color_sync",
			"property": "color_sync",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [],
			"clusters": {
				"input": ["genBasic", "genIdentify", "genGroups", "genScenes", "genOnOff", "genLevelCtrl", "lightingColorCtrl", "touchlink"],
				"output": ["genOta"]
			},
			"configured_reportings": [],
			"scenes": []
		},
		"242": {
			"bindings": [],
			"clusters": {
				"input": ["greenPower"],
				"output": ["greenPower"]
			},
			"configured_reportings": [],
			"scenes": []
		}
	},
	"friendly_name": "KAV-S1",
	"ieee_address": "0x680ae2fffe7ec70b",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "TRADFRIbulbG125E27WSopal470lm",
	"network_address": 29525,
	"power_source": "Mains (single phase)",
	"software_build_id": "1.0.012",
	"supported": true,
	"type": "Router"
}, {
	"date_code": "20201111",
	"definition": {
		"description": "TRADFRI LED globe-bulb E26/E27 450/470 lumen, dimmable, white spectrum, opal white",
		"exposes": [{
			"features": [{
				"access": 7,
				"description": "On/off state of this light",
				"name": "state",
				"property": "state",
				"type": "binary",
				"value_off": "OFF",
				"value_on": "ON",
				"value_toggle": "TOGGLE"
			}, {
				"access": 7,
				"description": "Brightness of this light",
				"name": "brightness",
				"property": "brightness",
				"type": "numeric",
				"value_max": 254,
				"value_min": 0
			}, {
				"access": 7,
				"description": "Color temperature of this light",
				"name": "color_temp",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}],
				"property": "color_temp",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}, {
				"access": 7,
				"description": "Color temperature after cold power on of this light",
				"name": "color_temp_startup",
				"presets": [{
					"description": "Coolest temperature supported",
					"name": "coolest",
					"value": 250
				}, {
					"description": "Cool temperature (250 mireds / 4000 Kelvin)",
					"name": "cool",
					"value": 250
				}, {
					"description": "Neutral temperature (370 mireds / 2700 Kelvin)",
					"name": "neutral",
					"value": 370
				}, {
					"description": "Warm temperature (454 mireds / 2200 Kelvin)",
					"name": "warm",
					"value": 454
				}, {
					"description": "Warmest temperature supported",
					"name": "warmest",
					"value": 454
				}, {
					"description": "Restore previous color_temp on cold power on",
					"name": "previous",
					"value": 65535
				}],
				"property": "color_temp_startup",
				"type": "numeric",
				"unit": "mired",
				"value_max": 454,
				"value_min": 250
			}],
			"type": "light"
		}, {
			"access": 2,
			"description": "Triggers an effect on the light (e.g. make light blink for a few seconds)",
			"name": "effect",
			"property": "effect",
			"type": "enum",
			"values": ["blink", "breathe", "okay", "channel_change", "finish_effect", "stop_effect"]
		}, {
			"access": 7,
			"description": "Controls the behavior when the device is powered on",
			"name": "power_on_behavior",
			"property": "power_on_behavior",
			"type": "enum",
			"values": ["off", "previous", "on"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "LED1936G5",
		"options": [{
			"access": 2,
			"description": "Controls the transition time (in seconds) of on/off, brightness, color temperature (if applicable) and color (if applicable) changes. Defaults to `0` (no transition).",
			"name": "transition",
			"property": "transition",
			"type": "numeric",
			"value_min": 0
		}, {
			"access": 2,
			"description": "When enabled colors will be synced, e.g. if the light supports both color x/y and color temperature a conversion from color x/y to color temperature will be done when setting the x/y color (default true).",
			"name": "color_sync",
			"property": "color_sync",
			"type": "binary",
			"value_off": false,
			"value_on": true
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [],
			"clusters": {
				"input": ["genBasic", "genIdentify", "genGroups", "genScenes", "genOnOff", "genLevelCtrl", "lightingColorCtrl", "touchlink"],
				"output": ["genOta"]
			},
			"configured_reportings": [],
			"scenes": []
		},
		"242": {
			"bindings": [],
			"clusters": {
				"input": ["greenPower"],
				"output": ["greenPower"]
			},
			"configured_reportings": [],
			"scenes": []
		}
	},
	"friendly_name": "KAV-L2",
	"ieee_address": "0x842e14fffe452bf4",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "TRADFRIbulbG125E27WSopal470lm",
	"network_address": 5597,
	"power_source": "Mains (single phase)",
	"software_build_id": "1.0.012",
	"supported": true,
	"type": "Router"
}, {
	"date_code": "20190715",
	"definition": {
		"description": "TRADFRI shortcut button",
		"exposes": [{
			"access": 1,
			"description": "Remaining battery in %",
			"name": "battery",
			"property": "battery",
			"type": "numeric",
			"unit": "%",
			"value_max": 100,
			"value_min": 0
		}, {
			"access": 1,
			"description": "Triggered action (e.g. a button click)",
			"name": "action",
			"property": "action",
			"type": "enum",
			"values": ["on", "brightness_move_up", "brightness_stop"]
		}, {
			"access": 1,
			"description": "Link quality (signal strength)",
			"name": "linkquality",
			"property": "linkquality",
			"type": "numeric",
			"unit": "lqi",
			"value_max": 255,
			"value_min": 0
		}],
		"model": "E1812",
		"options": [{
			"description": "Simulate a brightness value. If this device provides a brightness_move_up or brightness_move_down action it is possible to specify the update interval and delta.",
			"features": [{
				"access": 2,
				"description": "Delta per interval, 20 by default",
				"name": "delta",
				"property": "delta",
				"type": "numeric",
				"value_min": 0
			}, {
				"access": 2,
				"description": "Interval duration",
				"name": "interval",
				"property": "interval",
				"type": "numeric",
				"unit": "ms",
				"value_min": 0
			}],
			"name": "simulated_brightness",
			"property": "simulated_brightness",
			"type": "composite"
		}],
		"supports_ota": true,
		"vendor": "IKEA"
	},
	"endpoints": {
		"1": {
			"bindings": [{
				"cluster": "genPowerCfg",
				"target": {
					"id": 901,
					"type": "group"
				}
			}],
			"clusters": {
				"input": ["genBasic", "genPowerCfg", "genIdentify", "genAlarms", "genPollCtrl", "touchlink"],
				"output": ["genIdentify", "genGroups", "genOnOff", "genLevelCtrl", "genOta", "closuresWindowCovering", "touchlink"]
			},
			"configured_reportings": [{
				"attribute": "batteryPercentageRemaining",
				"cluster": "genPowerCfg",
				"maximum_report_interval": 62000,
				"minimum_report_interval": 3600,
				"reportable_change": 0
			}],
			"scenes": []
		}
	},
	"friendly_name": "0xb4e3f9fffec8fe45",
	"ieee_address": "0xb4e3f9fffec8fe45",
	"interview_completed": true,
	"interviewing": false,
	"manufacturer": "IKEA of Sweden",
	"model_id": "TRADFRI SHORTCUT Button",
	"network_address": 5687,
	"power_source": "Battery",
	"software_build_id": "2.3.015",
	"supported": true,
	"type": "EndDevice"
}]

*/