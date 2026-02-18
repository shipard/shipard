<?php

namespace mac\iot\libs;
use \lib\dataView\DataView, \Shipard\Utils\Utils, \Shipard\Utils\Json;



/**
 * class IoTDevicesList
 */
class IoTDevicesList extends DataView
{
	var $units;
	var $items;

  var $sensors = [];

	protected function init()
	{
		parent::init();
	}

	protected function loadData()
	{
		$this->loadDataSensors();
	}

	protected function loadDataSensors()
	{
    $quantityTypes = $this->app->cfgItem ('mac.data.quantityTypes');
    $placeTypes = $this->app->cfgItem ('e10.base.placeTypes');

		$q = [];
		array_push ($q, 'SELECT [sensors].*,');
    array_push ($q, ' [sensorsValues].value AS sensorValue,');
    array_push ($q, ' [places].shortName AS placeShortName, [places].fullName AS placeFullName, [places].[id] AS placeId, [places].[placeType] AS placeType');
		array_push ($q, ' FROM [mac_iot_sensors] AS [sensors]');
    array_push ($q, ' LEFT JOIN [mac_iot_sensorsValues] AS [sensorsValues] ON [sensorsValues].[ndx] = [sensors].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_base_places] AS [places] ON [places].[ndx] = [sensors].[place]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [sensors].[docState] = %i', 4000);
    array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
      $quantityType = $quantityTypes[$r['quantityType']] ?? [];
      $placeType = $placeTypes[$r['placeType']] ?? [];

			$item = [
        'sensorFullName' => $r['fullName'],
        'sensorShortName' => $r['shortName'],
        'quantityName' => $quantityType['title'] ?? NULL,
        'quantityType' => $quantityType['id'] ?? NULL,
        'quantityUnit' => $quantityType['unit'] ?? NULL,
        'sensorIdName' => $r['idName'],
        'sensorValue' => $r['sensorValue'],
        'sensorMqttTopic' => $r['srcMqttTopic'],
        'sensorUniqueId' => $r['ndx'],
        'placeShortName' => $r['placeShortName'],
        'placeFullName' => $r['placeFullName'],
        'placeId' => $r['placeId'],
        'placeType' => $r['placeType'],
        'placeTypeName' => $placeType['name'] ?? NULL,
			];

      $this->sensors[] = $item;
		}

    $this->data['sensors'] = $this->sensors;
	}

	protected function renderDataAs($showAs)
	{
    return $this->renderDataAsJson();
	}

	protected function renderDataAsJson()
	{
    unset($this->data['html']);
    unset($this->data['persons']);

		$c = '';
    $c .= Json::lint($this->data);
		return $c;
	}
}
