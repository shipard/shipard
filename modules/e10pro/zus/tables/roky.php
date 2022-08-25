<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';
use \E10\TableView, \E10\TableForm, \E10\HeaderData, \E10\DbTable, \E10\utils;


/**
 * Class TableRoky
 * @package E10Pro\Zus
 */
class TableRoky extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.roky", "e10pro_zus_roky", "Školní roky");
	}

	public function saveConfig ()
	{
		$ca = array ();

		$rows = $this->app()->db->query ('SELECT * from [e10pro_zus_roky] WHERE docState != 9800 ORDER BY [datumZacatek] DESC');
		forEach ($rows as $r)
		{
			if (!$r['datumZacatek'] || !$r['datumV1'] || !$r['datumV2'])
				continue;
			$id = $r['datumZacatek']->format ('Y');
			$ca [$id] = [
				'id' => $id, 'nazev' => $r ['nazev'], 'tisk' => $r ['tisk'],
				'zacatek' => $r['datumZacatek']->format ('Y-m-d'),
				'konec' => $r['datumKonec']->format ('Y-m-d'),
				'V1' => $r['datumV1']->format ('Y-m-d'), 'V2' => $r['datumV2']->format ('Y-m-d')
			];

			if ($r['datumKK1'])
				$ca [$id]['KK1'] = $r['datumKK1']->format ('Y-m-d');
			if ($r['datumKK2'])
				$ca [$id]['KK2'] = $r['datumKK2']->format ('Y-m-d');
		}

		// save to file
		$cfg ['e10pro']['zus']['roky'] = $ca;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.zus.roky.json', json_encode ($cfg));
	}

	public function tableIcon ($recData, $options = NULL)
	{
		switch ($recData['docState'])
		{
			case 1000: return 'system/docStateConfirmed';
			case 9000: return 'system/iconLocked';
			case 8000: return 'system/docStateEdit';
			case 4000: return 'system/docStateDone';
			case 9800: return 'system/docStateDelete';
		}
		return parent::tableIcon($recData, $options);
	}
}


/**
 * Class ViewRoky
 * @package E10Pro\Zus
 */
class ViewRoky extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'archive', 'title' => 'Uzavřeno');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item ['nazev'];
		$listItem ['t2'] = utils::datef($item ['datumZacatek']).' - '.utils::datef($item ['datumKonec']);

		$listItem ['i2'] = '1. pol.: '.utils::datef($item ['datumV1']).', 2. pol.: '.utils::datef($item ['datumV2']);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10pro_zus_roky] WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([nazev] LIKE %s OR [tisk] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] IN (0, 1, 2, 5)");

		// -- archive
		if ($mainQuery == 'archive')
			array_push ($q, " AND [docStateMain] = 5");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [datumZacatek] DESC, [nazev] ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY [docStateMain], [datumZacatek] DESC, [nazev] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class FormRok
 * @package E10Pro\Zus
 */
class FormRok extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->openForm ();
			$this->addColumnInput ("nazev");
			$this->addColumnInput ("tisk");
			$this->addColumnInput ("datumZacatek");
			$this->addColumnInput ("datumKonec");
			$this->addColumnInput ("datumV1");
			$this->addColumnInput ("datumV2");
			$this->addColumnInput ("datumKK1");
			$this->addColumnInput ("datumKK2");
		$this->closeForm ();
	}
}


