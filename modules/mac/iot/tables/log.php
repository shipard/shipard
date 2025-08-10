<?php

namespace mac\iot;
use \Shipard\Table\DbTable, \Shipard\Viewer\TableViewGrid, \Shipard\Viewer\TableViewDetail;


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
		//$this->usePanelLeft = TRUE;
		//$this->createLeftPanel();

		parent::init();


		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;

//		$this->setMainQueries ($mq);

		$g = [
      'dt' => 'Kdy',
			'itemType' => 'Typ',
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

    $listItem ['dt'] = $item['dt']->format('Y-m-d H:i:s');
    $listItem ['url'] = $item['requestUrl'];
    $listItem ['itemSubType'] = $item['itemSubType'];
    $listItem ['ip'] = $item['ipAddr'];

		return $listItem;
	}

	public function selectRows ()
	{
		$mq = $this->mainQueryId ();
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [iotLog].*');
		array_push ($q, ' FROM [mac_iot_log] AS [iotLog]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
      /*
			array_push ($q, ' AND (');
			array_push ($q, ' [items].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [itemCodes].[itemCodeText] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [balances].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
      */
		}

		array_push ($q, ' ORDER BY [ndx] DESC');
    array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}
}




