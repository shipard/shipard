<?php

namespace integrations\ntf;
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableDelivery
 * @package integrations\ntf
 */
class TableDelivery extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.ntf.delivery', 'integrations_ntf_delivery', 'Notifikační kanály', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

//		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewDelivery
 * @package integrations\ntf
 */
class ViewDelivery extends TableView
{
	var $channelsTypes;

	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		//$this->setMainQueries ();

		$this->channelsTypes = $this->app()->cfgItem('integration.ntf.channels.types');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['subject'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		//$channelType = $this->channelsTypes[$item['channelType']];
		//$listItem['t2'] = ['text' => $channelType['name'], 'icon' => $channelType['icon'], 'class' => 'label label-default'];

		$props2 = [];
		if ($item['doDelivery'])
			$props2[] = ['text' => 'Zatím nedoručeno', 'suffix' => utils::datef($item['dtNextTry'],'%k %T'), 'icon' => 'icon-hourglass-start', 'class' => 'label label-primary'];
		else
			$props2[] = ['text' => 'Doručeno', 'suffix' => utils::datef($item['dtDelivery'],'%k %T'), 'icon' => 'system/iconCheck', 'class' => 'label label-success'];

		$listItem['t2'] = $props2;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [integrations_ntf_delivery]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [subject] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push($q, ' ORDER BY ndx DESC');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class FormDelivery
 * @package integrations\ntf
 */
class FormDelivery extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag('formStyle', 'e10-formStyleSimple');
		$this->setFlag('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag('maximize', 1);
		$this->readOnly = 1;

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Info', 'icon' => 'icon-bullhorn'];
			$tabs ['tabs'][] = ['text' => 'Status', 'icon' => 'icon-wrench'];
			$tabs ['tabs'][] = ['text' => 'Data', 'icon' => 'icon-truck'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput('channel');
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput('lastStatus');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput('payload');
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDelivery
 * @package integrations\ntf
 */
class ViewDetailDelivery extends TableViewDetail
{
}


