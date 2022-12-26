<?php

namespace mac\iot\libs\dc;
use Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class DCEventBased
 */
class DCEventBased extends \Shipard\Base\DocumentCard
{
	/** @var \mac\iot\TableEventsDo */
	var $tableEventsDo;

	/** @var \mac\iot\TableEventsOn */
	var $tableEventsOn;

	var $rows = [];

	var $eventsBgs = ['e10-bg-t8', 'e10-bg-t2', 'e10-bg-t6'];
	var $eventsBgsCnt = 3;
	var $eventsBgsIdx = 0;
	var $bgClass = '';

  protected function init()
  {
    $this->tableEventsDo = $this->app()->table('mac.iot.eventsDo');
		$this->tableEventsOn = $this->app()->table('mac.iot.eventsOn');
  }

	protected function addEventsOn($tableId, $recId)
	{
		$qeo = [];
		$qeo [] = 'SELECT eventsOn.*,';
		array_push ($qeo, ' iotDevices.friendlyId AS deviceFriendlyId, iotDevices.fullName AS deviceFullName,');
		array_push ($qeo, ' iotSetups.id AS iotSetupId, iotSetups.fullName AS iotSetupFullName');
		array_push ($qeo, ' FROM [mac_iot_eventsOn] AS [eventsOn]');
		array_push ($qeo, ' LEFT JOIN [mac_iot_devices] AS iotDevices ON eventsOn.iotDevice = iotDevices.ndx');
		array_push ($qeo, ' LEFT JOIN [mac_iot_setups] AS iotSetups ON eventsOn.iotSetup = iotSetups.ndx');
		array_push ($qeo, ' WHERE 1');
		array_push ($qeo, ' AND [eventsOn].[tableId] = %s', $tableId);
		array_push ($qeo, ' AND [eventsOn].[recId] = %i', $recId);
		array_push ($qeo, ' AND [eventsOn].[docState] != %i', 9800);

		$eoRows = $this->db()->query($qeo);
		foreach ($eoRows as $r)
		{
			$this->bgClass = $this->eventsBgs[$this->eventsBgsIdx];

			$labels = [];
			$this->tableEventsOn->getEventLabels($r, $labels, TRUE);

      $docState = $this->tableEventsOn->getDocumentState ($r);
      $docStateClass = ' e10-ds-block '.$this->tableEventsOn->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');

			$item = [
				'handle' => $labels,
				'_options' => [
					'cellClasses' => ['handle' => $this->bgClass.$docStateClass, 'content' => $this->bgClass],
					'colSpan' => ['handle' => 2],
				]
			];
			$this->rows[] = $item;
			$this->addEventsDo ('mac.iot.eventsOn', $r['ndx'], 1);
			$this->eventsBgsIdx++;
			if ($this->eventsBgsIdx === $this->eventsBgsCnt)
				$this->eventsBgsIdx = 0;
		}
	}

	protected function addEventsDo ($tableId, $recId, $level)
	{
		$q [] = 'SELECT eventsDo.*,';
		array_push ($q, ' iotDevices.friendlyId AS deviceFriendlyId, iotDevices.fullName AS deviceFullName,');
		array_push ($q, ' devicesGroups.shortName AS devicesGroupName,');
		array_push ($q, ' iotSetups.id AS iotSetupId, iotSetups.fullName AS iotSetupFullName');
		array_push ($q, ' FROM [mac_iot_eventsDo] AS [eventsDo]');
		array_push ($q, ' LEFT JOIN [mac_iot_devices] AS iotDevices ON eventsDo.iotDevice = iotDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_devicesGroups] AS devicesGroups ON eventsDo.iotDevicesGroup = devicesGroups.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_setups] AS iotSetups ON eventsDo.iotSetup = iotSetups.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [eventsDo].[tableId] = %s', $tableId);
		array_push ($q, ' AND [eventsDo].[recId] = %i', $recId);
		array_push ($q, ' AND [eventsDo].[docStateMain] <= %i', 2);
		array_push ($q, ' ORDER BY [eventsDo].[rowOrder], [eventsDo].[ndx]');

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$labels = [];
			$this->tableEventsDo->getEventLabels($r, $labels, /*['text' => ' ➜ ', 'class' => 'clear break']*/NULL, TRUE);
      $docState = $this->tableEventsDo->getDocumentState ($r);
      $docStateClass = ' e10-ds-block '.$this->tableEventsDo->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');
			$item = [
				'content' => $labels,
				'handle' => '',
				'_options' => [
					'cellClasses' => ['handle' => $this->bgClass.' e10-icon', 'content' => $docStateClass],
				]
			];

			$this->rows[] = $item;
		}
	}
}
