<?php

namespace mac\iot\libs;
use \Shipard\Utils\Utils;


/**
 * class TemplateUI
 */
class TemplateESigns extends \Shipard\Utils\TemplateCore
{
  public function app() {return $this->app;}

	function resolveCmd ($tagCode, $tagName, $params)
	{
    switch ($tagName)
		{
			case	'iotSensor' 					: return $this->iotSensor ($params);
		}

    return parent::resolveCmd($tagCode, $tagName, $params);
  }

  public function subTemplateStr($stId)
	{
		$templateStr = file_get_contents(__SHPD_ROOT_DIR__.'/'.$stId.'.mustache');
		return $templateStr;
	}

  public function ioTSensor(array $params)
  {
    $data = ['valid' => 0, 'data' => []];

    $sensorNdx = intval($params['ndx'] ?? 0);
    if (!$sensorNdx)
    {
      return 'Missing sensor id';
    }

		$sensorRecData = $this->app()->db()->query(
      'SELECT [sensors].*, sensorsValues.value AS sensorValue FROM [mac_iot_sensors] AS [sensors] ',
			'LEFT JOIN [mac_iot_sensorsValues] AS sensorsValues ON sensors.ndx = sensorsValues.ndx',
			'WHERE [sensors].ndx = %i', $sensorNdx)->fetch();

    if (!$sensorRecData)
    {
      return 'Invalid sensor';
    }

    $data['data'] = $sensorRecData->toArray();

    $data['data']['sensorValueP0'] = round($sensorRecData['sensorValue'], 0);
    $data['data']['sensorValueP1'] = round($sensorRecData['sensorValue'], 1);
    $data['data']['sensorValueP2'] = round($sensorRecData['sensorValue'], 2);
    $data['data']['sensorValuePF0'] = Utils::nf($sensorRecData['sensorValue'], 0);
    $data['data']['sensorValuePF1'] = Utils::nf($sensorRecData['sensorValue'], 1);
    $data['data']['sensorValuePF2'] = Utils::nf($sensorRecData['sensorValue'], 2);

    $data['valid'] = 1;

    $icon = 'tables/mac.iot.sensors';
		if (isset($sensorRecData['sensorIcon']) && $sensorRecData['sensorIcon'] !== '')
		{
			$icon = $sensorRecData['sensorIcon'];
		}
		else
		{
			$qt = $this->app()->cfgItem('mac.data.quantityTypes.' . $sensorRecData['quantityType'], NULL);
			if ($qt)
			{
				$icon = $qt['icon'];
			}
		}

    $data['icon'] = $icon;

    $dataItemId = $params['item'] ?? 'data.sensorValue';
    return Utils::cfgItem($data, $dataItemId, 'unknown item');
  }
}
