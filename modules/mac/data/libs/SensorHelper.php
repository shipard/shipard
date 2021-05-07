<?php

namespace mac\data\libs;

use e10\Utility, \e10\utils, \e10\json, mac\iot\TableSensors;


/**
 * Class SensorHelper
 * @package mac\data\libs
 */
class SensorHelper extends Utility
{
	var $dataSource = NULL;
	var $sensorInfo = NULL;

	public function setSensor($sensorNdx)
	{
		$sensorRecData = $this->db()->query('SELECT [sensors].*, sensorsValues.value AS sensorValue FROM [mac_iot_sensors] AS [sensors] ',
			'LEFT JOIN [mac_iot_sensorsValues] AS sensorsValues ON sensors.ndx = sensorsValues.ndx',
			'WHERE [sensors].ndx = %i', $sensorNdx)->fetch();
		if (!$sensorRecData)
			return;

		$info = $sensorRecData->toArray();
		$info['sensorNdx'] = $info['ndx'];
		$info['lan'] = $sensorRecData['srcLan'];
		$info['topic'] = $sensorRecData['srcMqttTopic'];

		if (isset($sensorRecData['sensorIcon']) && $sensorRecData['sensorIcon'] !== '')
		{
			$info['icon'] = $sensorRecData['sensorIcon'];
		}
		else
		{
			$qt = $this->app()->cfgItem('mac.data.quantityTypes.' . $sensorRecData['quantityType'], NULL);
			if ($qt)
			{
				$info['icon'] = $qt['icon'];
			}
		}
		$this->setSensorInfo($info);
	}

	public function setSensorInfo ($info)
	{
		$this->sensorInfo = $info;
	}

	public function badgeCode($asBadge = 1, $mode = 1)
	{
		$c = '';
		$mainClass = $asBadge ? 'shp-badge' : '';
		$titleClass = $asBadge ? 'e10-bg-bt' : 'label';
		$contentClass = $asBadge ? 'e10-bg-bv-default' : '';

		$c .= "<span class='$mainClass' id='mqtt-sensor-{$this->sensorInfo['sensorNdx']}' data-sensorid='".$this->sensorInfo['sensorNdx']."'>";
		$c .= "<span class='$titleClass'>";

		$sensorIcon = (isset($this->sensorInfo['icon']) && $this->sensorInfo['icon'] !== '') ? $this->sensorInfo['icon'] : 'icon-barcode';
		$c .= "<i class='".$this->app()->ui()->icons()->cssClass($sensorIcon)."'></i> ";
		$c .= utils::es($this->sensorInfo['sensorBadgeLabel']);
		$c .= '</span>';
		if ($mode == 1)
		{
			$c .= "<span class='$contentClass value'> ";
			if (isset($this->sensorInfo['sensorValue']))
				$c .= $this->sensorInfo['sensorValue'];
			$c .= "</span>";
			$c .= "<span class='$contentClass unit'>" . $this->sensorInfo['sensorBadgeUnits'] . "</span>";
		}
		$c .= "</span>";

		return $c;
	}

	public function dsBadgeCode($label, $quantityId, $badgeParams)
	{
		$c = '';

		$params = [];
		$params['chart'] = $quantityId;
		$params['label'] = $label;

		if ($badgeParams)
			foreach ($badgeParams as $k => $v)
				$params[$k] = strval($v);

		if (!isset($params['refresh']))
			$params['refresh'] = 'auto';

		$url = $this->dataSource['url']."/api/v1/badge.svg".'?'.http_build_query($params);

		$c .= "<embed";
		$c .= " src='$url'";
		$c .= " type='image/svg+xml' height='20'>";

		return $c;
	}

	public function dsBadgeImg($label, $quantityId, $badgeParams)
	{
		$c = '';

		$params = [];
		$params['chart'] = $quantityId;
		$params['label'] = $label;

		if ($badgeParams)
			foreach ($badgeParams as $k => $v)
				$params[$k] = strval($v);

		$srcUrl = $this->dataSource['url']."/api/v1/badge.svg".'?'.http_build_query($params);

		$params['xyz'] = time();

		$url = $this->dataSource['url']."/api/v1/badge.svg".'?'.http_build_query($params);

		$c .= "<img class='e10-auto-reload'";
		$c .= " data-src='$srcUrl'";
		$c .= " src='$url'";
		$c .= "/>";

		return $c;
	}

	public function getSensor($asBadge, $mode)
	{
		$sensor = ['code' => $this->badgeCode($asBadge, $mode), 'flags' => []];
		if (isset($this->sensorInfo['topic']))
		{
			$sensor['topic'] = $this->sensorInfo['topic'];
			if (isset($this->sensorInfo['lan']))
			{
				$sensor['lan'] = $this->sensorInfo['lan'];
				$sensor['sensorNdx'] = $this->sensorInfo['sensorNdx'];

				if (isset($this->sensorInfo['flagLogin']) && $this->sensorInfo['flagLogin'])
				{
					$sensor['flags']['login'] = 1;
				}
				if (isset($this->sensorInfo['flagKbd']) && $this->sensorInfo['flagKbd'])
				{
					$sensor['flags']['kbd'] = $this->sensorInfo['flagKbd'];
				}

				$sensor['flags']['qt'] = $this->sensorInfo['quantityType'];
			}
		}

		return $sensor;
	}
}
