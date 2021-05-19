<?php

namespace mac\lan;
use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \e10\utils, \mac\lan\libs\alerts\AlertsUtils;
use function E10\searchArray;


/**
 * Class TableAlerts
 * @package mac\lan
 */
class TableAlerts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.alerts', 'mac_lan_alerts', 'Výstrahy');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}
}


/**
 * Class ViewAlerts
 * @package mac\lan
 */
class ViewAlerts extends TableView
{
	var $alertsTypes;
	var $alertsScopes;
	var $watchdogs;

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
		$this->alertsTypes = $this->app()->cfgItem('mac.lan.alerts.types');
		$this->alertsScopes = $this->app()->cfgItem('mac.lan.alerts.scopes');
		$this->watchdogs = $this->app()->cfgItem('mac.lan.watchdogs');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$alertType = $this->alertsTypes[$item['alertType']];
		$alertScope = $this->alertsScopes[$item['alertScope']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $alertType['fn'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];


		$props = [];

		$props[] = ['text' => $alertScope['fn'], 'class' => 'label label-default'];

		if ($item['alertType'] === AlertsUtils::atWatchdogTimeout)
		{
			$watchdog = searchArray($this->watchdogs, 'ndx', $item['alertSubtype']);
			$props[] = ['text' => $watchdog['fn'], 'class' => 'label label-default'];
		}

		$listItem['t2'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [mac_lan_alerts] AS [alerts]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			/*
			array_push ($q, ' AND (');
			array_push ($q,
				' [alerts].[title] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
			*/
		}

		$this->queryMain ($q, '[alerts].', ['[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailAlert
 * @package mac\lan
 */
class ViewDetailAlert extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.dc.Alert');
	}
}


/**
 * Class FormAlert
 * @package mac\lan
 */
class FormAlert extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();

				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
