<?php

namespace e10pro\zus;

use \Shipard\Viewer\TableView;
use \Shipard\Form\TableForm;
use \Shipard\Table\DbTable;


/**
 * class TableOddeleni
 */
class TableOddeleni extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.oddeleni", "e10pro_zus_oddeleni", "Oddělení");
	}

  public function saveConfig ()
	{
		$ca = array ();

		$rows = $this->app()->db->query ("SELECT * from [e10pro_zus_oddeleni] WHERE docState != 9800 ORDER BY [pos], [nazev]");
		forEach ($rows as $r)
		{
			$itm = ['id' => $r['ndx'], 'nazev' => $r ['nazev'], 'tisk' => $r['tisk'], 'svp' => $r ['svp'], 'obor' => $r ['obor'], 'oznaceni' => $r ['id']];
			if ($itm['tisk'] === '')
				$itm['tisk'] = $r ['nazev'];
			$ca [$r['ndx']] = $itm;
		}
		$ca [0] = ['id' => 0, 'nazev' => '---', 'tisk' => '', 'svp' => 0, 'obor' => 0,  'oznaceni' => ''];

		// save to file
		$cfg ['e10pro']['zus']['oddeleni'] = $ca;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.zus.oddeleni.json', json_encode ($cfg));
	}
}


/**
 * class ViewOddeleni
 */
class ViewOddeleni extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['nazev'];
		$listItem ['icon'] = 'tables/e10pro.zus.oddeleni';

		if ($item ['svp'])
			$listItem ['t2'][] = ['text' => $this->app()->cfgItem ("e10pro.zus.svp.{$item ['svp']}.nazev")];

		if ($item ['obor'])
			$listItem ['t2'][] = ['text' => 'obor '.$this->app()->cfgItem ("e10pro.zus.obory.{$item ['obor']}.nazev")];

		if ($item['pos'])
			$listItem['i2'] = ['icon' => 'icon-sort', 'text' => strval ($item['pos'])];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = "SELECT * from [e10pro_zus_oddeleni] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND [nazev] LIKE %s", '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// -- archive
		if ($mainQuery == 'archive')
			array_push ($q, " AND [docStateMain] = 5");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [pos], [nazev] ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY [docStateMain], [pos], [nazev] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * class FormOddeleni
 */
class FormOddeleni extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('maximize', 1);
    $this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Pobočky', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('svp');
					$this->addColumnInput ('obor');
					$this->addColumnInput ('nazev');
					$this->addColumnInput ('tisk');
					$this->addColumnInput ('id');
					$this->addColumnInput ('pos');

					$this->addColumnInput ('stop');
				$this->closeTab ();
				$this->openTab ();
					$this->addList ('pobocky');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

