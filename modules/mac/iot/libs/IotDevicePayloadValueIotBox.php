<?php

namespace mac\iot\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Utils, \Shipard\Utils\json;


/**
 * Class IotDevicePayloadValueIotBox
 */
class IotDevicePayloadValueIotBox extends Utility
{
	var $eventValueCfg = NULL;

	protected function binaryValue($enumId)
	{
		if ($enumId === 'push')
		{
			$interval = intval($this->eventValueCfg['interval']);
			if (!$interval)
				$interval = 50;
			return 'P'.$interval;
		}
		if ($enumId === 'unpush')
		{
			$interval = intval($this->eventValueCfg['interval']);
			if (!$interval)
				$interval = 50;
			return 'U'.$interval;
		}

		return '';
	}

	protected function ledStripValue($enumId)
	{
		if ($enumId === 'off')
			return 'static:0:000000';

		if ($enumId === 'static')
		{
			$color = $this->color('color', '003300');
			return 'static:0:'.$color;
		}

		if ($enumId === 'larson-scanner')
		{
			$interval = intval($this->eventValueCfg['interval']);
			if (!$interval)
				$interval = 1000;
			$colorActive = $this->color('colorActive', '330000');
			return 'larson-scanner:'.$interval.':'.$colorActive;
		}

		if ($enumId === 'scan' || $enumId === 'dual-scan')
		{
			$interval = intval($this->eventValueCfg['interval']);
			if (!$interval)
				$interval = 2000;
			$colorActive = $this->color('colorActive', '333333');
			$colorBg = $this->color('colorBg', '000000');
			return $enumId.':'.$interval.':'.$colorActive.':'.$colorBg;
		}

		if ($enumId === 'breath')
		{
			$colorActive = $this->color('colorActive', '333333');
			$colorBg = $this->color('colorBg', '000000');
			return 'breath:0:'.$colorActive.':'.$colorBg;
		}

		if ($enumId === 'manual-settings')
		{
			return trim($this->eventValueCfg['payload']);
		}

		return '';
	}

	protected function color ($id, $defaultValue)
	{
		$color = $this->eventValueCfg[$id] ?? $defaultValue;
		if ($color === '')
			$color = $defaultValue;
		if ($color[0] === '#')
			$color = substr($color, 1);
		$color = strtolower($color);

		return $color;
	}

	public function enumValue ($eventRecData, $deviceProperty, $enumSetValue)
	{
		$this->eventValueCfg = json_decode($eventRecData['eventValueCfg'], TRUE);
		if (!$this->eventValueCfg)
			$this->eventValueCfg = [];

		if (isset($deviceProperty['ioPortType']) && $deviceProperty['ioPortType'] === 'control/led-strip')
			return $this->ledStripValue($eventRecData['iotDevicePropertyValueEnum']);
		if (isset($deviceProperty['ioPortType']) && $deviceProperty['ioPortType'] === 'control/binary')
			return $this->binaryValue($eventRecData['iotDevicePropertyValueEnum']);

		return '';
	}
}