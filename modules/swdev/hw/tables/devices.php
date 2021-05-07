<?php

namespace swdev\hw;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableDevices
 * @package swdev\hw
 */
class TableDevices extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.hw.devices', 'swdev_hw_devices', 'HW Zařízení');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		$deviceKind = $this->app()->cfgItem ('swdev.hw.devices.kinds.'.$recData['deviceKind'], FALSE);

		if ($deviceKind)
			return $deviceKind['icon'];

		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewDevices
 * @package swdev\hw
 */
class ViewDevices extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();

		//$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.utils::nf($item['ndx']), 'class' => 'id'];
		$listItem ['t2'] = $item['id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [devices].*';

		array_push ($q, ' FROM [swdev_hw_devices] AS [devices]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([devices].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [devices].[shortName] LIKE %s', '%'.$fts.'%',
				' OR [devices].[id] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[devices].', ['[devices].[fullName]', '[devices].[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormDevice
 * @package swdev\hw
 */
class FormDevice extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Zařízení', 'icon' => 'icon-fax'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');

					$this->addColumnInput ('vendor');
					$this->addColumnInput ('deviceKind');
					$this->addColumnInput ('deviceSeries');
					$this->addColumnInput ('deviceFW');
					$this->addColumnInput ('deviceArch');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDevice
 * @package swdev\hw
 */
class ViewDetailDevice extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
