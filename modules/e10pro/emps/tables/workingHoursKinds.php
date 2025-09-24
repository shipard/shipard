<?php

namespace e10pro\emps;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableWorkingHoursKinds
 */
class TableWorkingHoursKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.emps.workingHoursKinds', 'e10pro_emps_workingHoursKinds', 'Druhy pracovní doby');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10pro_emps_workingHoursKinds] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'],
				'fn' => $r ['fullName'],
				'sn' => $r ['shortName'],
				'useTwoHours' => intval($r ['useTwoHours']),
				'usePedagogicalActivity' => intval($r ['usePedagogicalActivity']),
				'wlWeeklyColTitle' => $r ['wlWeeklyColTitle'],
				'wlWeekly1ColTitle' => $r ['wlWeekly1ColTitle'],
				'wlWeekly2ColTitle' => $r ['wlWeekly2ColTitle'],
			];

			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg['e10pro']['emps']['workingHoursKinds'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.emps.workingHoursKinds.json', Utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * class ViewWorkingHoursKinds
 */
class ViewWorkingHoursKinds extends TableView
{
	public function init ()
	{
		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];

    $props = [];
		if ($item['order'])
			$props[] = ['text' => Utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];
		if (count($props))
			$listItem ['i2'] = $props;

    $listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [whKinds].*');
    array_push ($q, ' FROM [e10pro_emps_workingHoursKinds] AS [whKinds]');
    array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
    {
      array_push ($q, 'AND (');
			array_push ($q, '[whKinds].[fullName] LIKE %s ', '%'.$fts.'%');
			array_push ($q, ' OR [whKinds].[shortName] LIKE %s ', '%'.$fts.'%');
      array_push ($q, ')');
    }

		$this->queryMain ($q, '[whKinds].', ['[order]', '[fullName]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormWorkingHoursKind
 */
class FormWorkingHoursKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
          $this->addColumnInput ('order');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('useTwoHours');
					$this->addColumnInput ('usePedagogicalActivity');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('wlWeeklyColTitle');
					$this->addColumnInput ('wlWeekly1ColTitle');
					$this->addColumnInput ('wlWeekly2ColTitle');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailWorkingHoursKind
 */
class ViewDetailWorkingHoursKind extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
