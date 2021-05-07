<?php

namespace swdev\hw;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableDevicesKinds
 * @package swdev\hw
 */
class TableDevicesKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.hw.devicesKinds', 'swdev_hw_devicesKinds', 'Druhy HW');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}

	public function saveConfig ()
	{
		$list = [];
		$rows = $this->app()->db->query ('SELECT * FROM [swdev_hw_devicesKinds] WHERE [docState] != 9800 ORDER BY [order], [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$deviceType = $this->app()->cfgItem('swdev.hw.devices.types.'.$r['deviceType'], []);

			$kind = [
				'ndx' => $r ['ndx'],
				'fn' => $r ['fullName'], 'sn' => ($r['shortName'] !== '') ? $r['shortName'] : $r['fullName'],
				'type' => $r['deviceType'],
				'icon' => ($r['icon'] !== '') ? $r['icon'] : $deviceType['icon'],
			];

			$list [$r['ndx']] = $kind;
		}

		// -- save to file
		$cfg ['swdev']['hw']['devices']['kinds'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_swdev.hw.devices.kinds.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewDevicesKinds
 * @package swdev\hw
 */
class ViewDevicesKinds extends TableView
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

		$q [] = 'SELECT [kinds].*';

		array_push ($q, ' FROM [swdev_hw_devicesKinds] AS [kinds]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([kinds].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [kinds].[shortName] LIKE %s', '%'.$fts.'%',
				' OR [kinds].[id] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[kinds].', ['[kinds].[fullName]', '[kinds].[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormDeviceKind
 * @package swdev\hw
 */
class FormDeviceKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Druh', 'icon' => 'icon-server'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('deviceType');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDeviceKind
 * @package swdev\hw
 */
class ViewDetailDeviceKind extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
