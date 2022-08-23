<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';
use \E10\TableView, \E10\TableForm, \E10\HeaderData, \E10\DbTable;


/**
 * Class TableRocniky
 * @package E10Pro\Zus
 */
class TableRocniky extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.rocniky", "e10pro_zus_rocniky", "Ročníky");
	}

	public function saveConfig ()
	{
		$ca = array ();

		$rows = $this->app()->db->query ("SELECT * from [e10pro_zus_rocniky] ORDER BY [poradi]");
		forEach ($rows as $r)
			$ca [$r['ndx']] = array ('id' => $r['ndx'], 'nazev' => $r ['nazev'], 'tisk' => $r ['tisk'], 'zkratka' => $r['zkratka'], 'stupen' => $r ['stupen'], 'typVysvedceni' => $r ['typVysvedceni']);

		// save to file
		$cfg ['e10pro']['zus']['rocniky'] = $ca;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.zus.rocniky.json', json_encode ($cfg));
	}
}


/**
 * Class ViewRocniky
 * @package E10Pro\Zus
 */
class ViewRocniky extends TableView
{
	var $typyVysvedceni;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		$this->typyVysvedceni = $this->table->columnInfoEnum ('typVysvedceni', 'cfgText');

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item ['nazev'];
		$listItem ['t2'] = $item ['nazevStupne'].' '.'stupeň';
		$listItem ['i2'] = $this->typyVysvedceni [$item ['typVysvedceni']];

		$listItem ['i1'] = $item ['zkratka'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT rocniky.*, stupne.nazev as nazevStupne from [e10pro_zus_rocniky] as rocniky ';
		array_push($q, ' LEFT JOIN e10pro_zus_stupne as stupne ON rocniky.stupen = stupne.ndx');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND (rocniky.[nazev] LIKE %s OR rocniky.[tisk] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND rocniky.[docStateMain] < 4");

		// -- archive
		if ($mainQuery == 'archive')
			array_push ($q, " AND rocniky.[docStateMain] = 5");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND rocniky.[docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [poradi], [nazev] ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY rocniky.[docStateMain], [poradi], [nazev] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class FormRocnik
 * @package E10Pro\Zus
 */
class FormRocnik extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->openForm ();
			$this->addColumnInput ("nazev");
			$this->addColumnInput ("tisk");
			$this->addColumnInput ("zkratka");
			$this->addColumnInput ("poradi");
			$this->addColumnInput ("stupen");
			$this->addColumnInput ("typVysvedceni");
		$this->closeForm ();
	}
}


