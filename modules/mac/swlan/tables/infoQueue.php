<?php

namespace mac\swlan;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableInfoQueue
 * @package mac\swlan
 */
class TableInfoQueue extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.swlan.infoQueue', 'mac_swlan_infoQueue', 'SW informace ke zpracování');
	}


	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData['title']];
		$hdr ['info'][] = ['class' => 'info', 'value' => '#'.$recData['ndx'].' | '.$recData['checksumOriginal']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['osInfo'])
		{
			$osFamily = $this->app()->cfgItem('mac.swcore.osFamily.'.$recData['osFamily']);
			return $osFamily['icon'];
		}

		return parent::tableIcon ($recData, $options);
	}

}


/**
 * Class ViewInfoQueue
 * @package mac\swlan
 */
class ViewInfoQueue extends TableView
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;

	var $devicesKinds;
	var $osFamily;

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->osFamily = $this->app()->cfgItem ('mac.swcore.osFamily');

		$this->setMainQueries ();
	}

	function createToolbar()
	{
		return [];
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
//		$listItem ['i1'] = ['text' => '#'.$item['ndx'].';'.substr($item['checksumOriginal'], 0, 3).'...'.substr($item['checksumOriginal'],-3), 'class' => 'id'];
		$listItem ['t1'] = $item['title'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		$props[] = [
			'text' => utils::datef($item['dateCreate'], '%S %T'), 'class' => 'label label-default', 'icon' => 'icon-play',
			'suffix' => $item['ipAddress'],
		];

		if ($item['cntSameAsOriginal'])
			$props[] = [
				'text' => utils::datef($item['dateSameAsOriginal'], '%S %T'), 'class' => 'label label-default', 'icon' => 'icon-repeat',
				'prefix' => $item['cntSameAsOriginal'].'x',
			];

		if ($item['osUserId'] !== '')
			$props[] = ['text' => $item['osUserId'], 'class' => 'label label-default', 'icon' => 'icon-user'];

		$props[] = [
			'text' => $item['deviceFullName'], 'class' => 'label label-default',
			'suffix' => $item['deviceId'],
			'icon' => $this->devicesKinds[$item['deviceKind']]['icon'],
		];

		$listItem ['t2'] = $props;

		$props = [];
		if ($item['docState'] >= 1200)
		{
			$l = ['text' => $item['swSUID'], 'prefix' => 'sw', 'class' => 'label label-info'];
			$props[] = $l;
			$l = ['text' => $item['swVersionSUID'], 'prefix' => 'ver', 'class' => 'label label-info'];
			$props[] = $l;
		}
		if (count($props))
			$listItem['t3'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [infoQueue].*, ';
		array_push ($q, ' devices.fullName AS [deviceFullName], devices.id AS [deviceId], devices.deviceKind AS [deviceKind]');
		array_push ($q, ' FROM [mac_swlan_infoQueue] AS [infoQueue]');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON infoQueue.device = devices.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [infoQueue].[title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [infoQueue].[dataOriginal] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'infoQueue.', ['[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormInfoQueue
 * @package mac\swlan
 */
class FormInfoQueue  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Zařízení', 'icon' => 'icon-cogs'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('deviceUid');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailInfoQueue
 * @package mac\swlan
 */
class ViewDetailInfoQueue extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.swlan.dc.InfoQueue');
	}
}

