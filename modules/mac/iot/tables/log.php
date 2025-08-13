<?php

namespace mac\iot;
use \Shipard\Table\DbTable, \Shipard\Viewer\TableViewGrid, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * Class TableLog
 */
class TableLog extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.log', 'mac_iot_log', 'Logy IoT zařízení');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
	}

  public function addLogItem($data)
  {
    $newItem = $data;

    if (!isset($newItem['dt']))
      $newItem['dt'] = new \DateTime();

    if (!isset($newItem['requestUrl']))
      $newItem['requestUrl'] = $this->app()->requestPath();

    if (!isset($newItem['ipAddr']))
      $newItem['ipAddr'] = $_SERVER ['REMOTE_ADDR'] ?? '';

    $this->db()->query('INSERT INTO [mac_iot_log] ', $newItem);
  }
}


/**
 * class ViewLog
 */
class ViewLog extends TableViewGrid
{
	public function init ()
	{
		parent::init();

    $this->type = 'form';

    $this->fullWidthToolbar = TRUE;
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;
		$this->objectSubType = self::vsMain;
		$this->linesWidth = 70;
		$this->setPanels (self::sptQuery);

		$g = [
      'dt' => '_Okamžik',
      'device' => 'Zařízení',
      'itemSubType' => 'Subtyp',
      'url' => 'URL',
      'ip' => 'IP adresa',
		];

		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

    $listItem ['dt'] = Utils::datef($item['dt'], '%k').' '.$item['dt']->format('H:i:s');
    $listItem ['url'] = $item['requestUrl'];
    $listItem ['itemSubType'] = $item['itemSubType'];
    $listItem ['ip'] = $item['ipAddr'];

    if ($item['iotDeviceFriendlyId'])
      $listItem ['device'] = ['text' => $item['iotDeviceFriendlyId'], 'class' => 'label label-default', 'icon' => 'tables/mac.iot.devices'];

		return $listItem;
	}

	public function selectRows ()
	{
		$mq = $this->mainQueryId ();
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [iotLog].*, ');
    array_push ($q, ' [iotDevices].[fullName] AS [iotDevice], [iotDevices].[hwId] AS [deviceHwId], [iotDevices].[friendlyId] AS [iotDeviceFriendlyId]');
		array_push ($q, ' FROM [mac_iot_log] AS [iotLog]');
    array_push ($q, ' LEFT JOIN [mac_iot_devices] AS [iotDevices] ON [iotLog].[iotDevice] = [iotDevices].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [iotLog].[requestUrl] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [iotDevices].[friendlyId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [ndx] DESC');
    array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailLog
 */
class ViewDetailLog extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.iot.dc.DCLogItem');
	}
}
