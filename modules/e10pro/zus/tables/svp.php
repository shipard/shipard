<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';


use \E10\Application;
use \E10\TableView;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;


/**
 * Tabulka ŠVP
 *
 */

class TableSvp extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.svp", "e10pro_zus_svp", "ŠVP");
	}

  public function saveConfig ()
	{
		$ca = [];

		$rows = $this->app()->db->query ("SELECT * from [e10pro_zus_svp] WHERE docState != 9800 ORDER BY [poradi], [id]");
		forEach ($rows as $r)
			$ca [$r['ndx']] = ['id' => $r['ndx'], 'nazev' => $r ['nazev'], 'pojmenovani' => $r ['pojmenovani']];

		// save to file
		$cfg ['e10pro']['zus']['svp'] = $ca;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.zus.svp.json', json_encode ($cfg));
	}
}


/**
 * Základní pohled na ŠVP
 *
 */

class ViewSvp extends TableView
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
		$listItem ['i1'] = $item ['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		if ($item['poradi'])
			$listItem['i2'] = ['icon' => 'icon-sort', 'text' => strval ($item['poradi'])];

		return $listItem;
	}
} // class ViewSvp


/*
 * FormSvp
 *
 */

class FormSvp extends TableForm
{
	public function renderForm ()
	{
		$this->openForm ();
			$this->addColumnInput ("nazev");
			$this->addColumnInput ("pojmenovani");
			$this->addColumnInput ("id");
			$this->addColumnInput ("poradi");
		$this->closeForm ();
	}

  public function createHeaderCode ()
	{
		$item = $this->recData;
		$info = '';
		return $this->defaultHedearCode ("e10pro-zus-svp", $item ['nazev'], $info);
	}
} // class FormSvp


