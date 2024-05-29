<?php

namespace e10pro\soci;

use \Shipard\Utils\Utils, \Shipard\Utils\Json, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView;


/**
 * class TablePeriods
 */
class TablePeriods extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.soci.periods', 'e10pro_soci_periods', 'Období');
	}

	public function saveConfig ()
	{
		$usersPeriods = [];

		$ca = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10pro_soci_periods] WHERE docState != 9800 ORDER BY [dateBegin] DESC');
		forEach ($rows as $r)
		{
			if (!$r['dateBegin'] || !$r['dateEnd'])
				continue;
			$ca [$r['ndx']] = [
				'fn' => $r ['fullName'], 'sn' => $r ['shortName'],
				'dateBegin' => $r['dateBegin']->format ('Y-m-d'),
				'dateEnd' => $r['dateEnd']->format ('Y-m-d'),
				'dateHalf' => $r['dateHalf']->format ('Y-m-d'),
			];

			$upId = 'AY'.$r['ndx'];
			$up = [
				'id' => $upId,
				'fn' => $r ['fullName'], 'sn' => $r ['shortName'],
				'dateBegin' => $r['dateBegin']->format ('Y-m-d'),
				'dateEnd' => $r['dateEnd']->format ('Y-m-d'),
				'dateHalf' => $r['dateHalf']->format ('Y-m-d'),
				'done' => intval($r['docState'] === 9000),
			];
			$usersPeriods[$upId] = $up;
		}

		// save to file
		$cfg ['e10pro']['soci']['periods'] = $ca;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.soci.periods.json', Json::lint ($cfg));

		$cfgUP ['e10']['usersPeriods'] = $usersPeriods;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.soci.usersPeriods.json', Json::lint ($cfgUP));
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
 * class ViewPeriods
 */
class ViewPeriods extends TableView
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

		$listItem ['t1'] = $item ['fullName'];
		$listItem ['t2'] = utils::datef($item ['dateBegin']).' - '.utils::datef($item ['dateEnd']);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10pro_soci_periods] WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

    $this->queryMain ($q, '', ['[dateBegin] DESC', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormPeriod
 */
class FormPeriod extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('dateBegin');
			$this->addColumnInput ('dateHalf');
			$this->addColumnInput ('dateEnd');
		$this->closeForm ();
	}
}
