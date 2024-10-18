<?php

namespace e10mnf\base;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableWorkActivities
 */
class TableWorkActivities extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.base.workActivities', 'e10mnf_base_workActivities', 'Pracovní činnosti');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10mnf_base_workActivities] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$item = ['ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'icon' => $r['icon']];
			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg ['e10mnf']['base']['workActivities'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10mnf.base.workActivities.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * class ViewWorkActivities
 */
class ViewWorkActivities extends TableView
{
	public function init ()
	{
		parent::init();
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item ['order']), 'class' => 'pull-right'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT * FROM [e10mnf_base_workActivities] as wa');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' wa.[fullName] LIKE %s', '%'.$fts.'%',
				' OR wa.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'wa.', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailWorkActivity
 */
class ViewDetailWorkActivity extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * class FormWorkActivity
 */
class FormWorkActivity extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
				$this->closeTab();
					$this->openTab ();
					$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
