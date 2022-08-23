<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';
use \E10\TableView, \E10\TableForm, \E10\HeaderData, \E10\DbTable;


/**
 * Class TableStupne
 * @package E10Pro\Zus
 */
class TableStupne extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.stupne", "e10pro_zus_stupne", "Vzdělávací stupně");
	}

	public function saveConfig ()
	{
		$ca = array ();

		$rows = $this->app()->db->query ("SELECT * from [e10pro_zus_stupne] WHERE docState != 9800 ORDER BY [poradi]");
		forEach ($rows as $r)
			$ca [$r['ndx']] = array ('id' => $r['ndx'], 'nazev' => $r ['nazev'], 'tisk' => $r ['tisk']);

		// save to file
		$cfg ['e10pro']['zus']['stupne'] = $ca;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.zus.stupne.json', json_encode ($cfg));
	}
}


/**
 * Class ViewStupne
 * @package E10Pro\Zus
 */
class ViewStupne extends TableView
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
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item ['nazev'];
		$listItem ['t2'] = $item ['tisk'];
		$listItem ['i1'] = strval($item ['poradi']);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = "SELECT * from [e10pro_zus_stupne] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([nazev] LIKE %s OR [tisk] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

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
			array_push ($q, ' ORDER BY [poradi], [nazev] ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY [docStateMain], [poradi], [nazev] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class FormStupen
 * @package E10Pro\Zus
 */
class FormStupen extends TableForm
{
	public function renderForm ()
	{
		$this->openForm ();
			$this->addColumnInput ("nazev");
			$this->addColumnInput ("tisk");
			$this->addColumnInput ("poradi");
		$this->closeForm ();
	}
}


