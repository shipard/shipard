<?php

namespace mac\lan;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableDeviceTypes
 * @package mac\lan
 */
class TableDeviceTypes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.deviceTypes', 'mac_lan_deviceTypes', 'Zařízení v síti');
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return $this->app()->cfgItem ('mac.lan.devices.kinds.'.$recData['deviceKind'].'.icon', 'x-cog');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = array ('class' => 'title', 'value' => $recData ['fullName']);
		$h ['info'][] = array ('class' => 'info', 'value' => $recData ['deviceTypeName']);

		return $h;
	}
}


/**
 * Class ViewDeviceTypes
 * @package mac\lan
 */
class ViewDeviceTypes extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

/*		$props = array ();

		if (count($props))
			$listItem ['t2'] = $props;*/

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = "SELECT * FROM [mac_lan_deviceTypes] WHERE 1";


		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s)", '%'.$fts.'%');

		// -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// koš
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		array_push ($q, ' ORDER BY [fullName] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class FormDeviceType
 * @package mac\lan
 */
class FormDeviceType extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Porty', 'icon' => 'formPorts'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openTabs ($tabs);
			$this->openTab ();
				$this->addColumnInput ('deviceKind');
				$this->addColumnInput ('fullName');
				//$this->addColumnInput('deviceTypeName');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addList ('ports');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

		$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDeviceType
 * @package mac\lan
 */
class ViewDetailDeviceType extends TableViewDetail
{
}

