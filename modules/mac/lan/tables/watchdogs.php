<?php

namespace mac\lan;

use Dibi\DateTime;
use \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\utils, \e10\str;


/**
 * Class TableWatchdogs
 * @package mac\lan
 */
class TableWatchdogs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.watchdogs', 'mac_lan_watchdogs', 'Watchdogs');
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'test'];

		return $hdr;
	}

	public function pingFromDevice ($wdId, $deviceUID, $data)
	{
		$deviceRecData = $this->db()->query('SELECT ndx FROM [mac_lan_devices] WHERE [uid] = %s', $deviceUID)->fetch();
		if (!$deviceRecData)
			return FALSE;

		$this->touchFromDevice ($wdId, $deviceRecData['ndx'], $data);

		return TRUE;
	}

	public function touchFromDevice ($wdId, $deviceNdx, $data)
	{
		$dataStr = str::upToLen($data, 80);

		$exist = $this->db()->query('SELECT * FROM [mac_lan_watchdogs] WHERE watchdog = %s', $wdId, ' AND [device] = %i', $deviceNdx)->fetch();
		if (!$exist)
		{
			$newItem = [
				'watchdog' => $wdId, 'device' => $deviceNdx,
				'time1' => new \DateTime(), 'data1' => $dataStr,
				'counter' => 1
			];
			$this->db()->query('INSERT INTO [mac_lan_watchdogs]', $newItem);
			return 1;
		}

		$update = [
			'time3' => $exist['time2'], 'time2' => $exist['time1'], 'time1' => new DateTime(),
			'data3' => $exist['data2'], 'data2' => $exist['data1'], 'data1' => $dataStr,
			'counter' => $exist['counter'] + 1,
		];

		$this->db()->query('UPDATE [mac_lan_watchdogs] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);

		return $update['counter'];
	}
}


/**
 * Class ViewWatchdogs
 * @package mac\lan
 */
class ViewWatchdogs extends TableView
{
	var $watchdogs;

	public function init ()
	{
		parent::init();

		$this->watchdogs = $this->app()->cfgItem('mac.lan.watchdogs');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['deviceName'];
		//$listItem ['i1'] = ['text' => '#'.$item['device'].'.'.$item['deviceId'], 'class' => 'id'];

		$wd = isset($this->watchdogs[$item['watchdog']]) ? $this->watchdogs[$item['watchdog']] : NULL;

		$props = [];
		if ($wd)
			$props[] = ['text' => $wd['fn'], 'icon' => 'icon-heartbeat', 'class' => 'label label-default'];

		$props[] = ['text' => utils::nf($item['counter']), 'icon' => 'icon-spinner', 'class' => 'label label-default'];

		$listItem ['t2'] = $props;


		$props = [];
		if ($item['time1'])
			$props[] = ['text' => utils::datef($item['time1'], '%S %f'), 'class' => 'label label-default', 'title' => $item['data1']];
		if ($item['time2'])
			$props[] = ['text' => utils::datef($item['time2'], '%S %f'), 'class' => 'label label-default', 'title' => $item['data2']];
		if ($item['time3'])
			$props[] = ['text' => utils::datef($item['time3'], '%S %f'), 'class' => 'label label-default', 'title' => $item['data3']];

		$listItem ['t3'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [wd].*, devices.fullName AS deviceName, devices.id AS deviceId';
		array_push ($q, ' FROM [mac_lan_watchdogs] AS [wd]');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON wd.device = devices.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (devices.[fullName] LIKE %s', '%' . $fts . '%',
				'OR devices.[id] LIKE %s', '%' . $fts . '%',
				')');
		}

		// -- aktuální
		//if ($mainQuery == 'active' || $mainQuery == '')
		//	array_push ($q, " AND lans.[docStateMain] < 4");

		// koš
		array_push ($q, ' ORDER BY [devices].[fullName], [wd].ndx ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class FormWatchdog
 * @package mac\lan
 */
class FormWatchdog extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('watchdog');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailWatchdog
 * @package mac\lan
 */
class ViewDetailWatchdog extends TableViewDetail
{
}

