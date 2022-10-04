<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';

use \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableTeachPlanHeads
 * @package E10Pro\Zus
 */
class TableTeachPlanHeads extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.teachplanheads', 'e10pro_zus_teachplanheads', 'Učební plány');
	}
}


/**
 * Class ViewTeachPlans
 * @package E10Pro\Zus
 */
class ViewTeachPlans extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		$eduPrograms = $this->table->app()->cfgItem ('e10pro.zus.svp', []);
		$activeEduProgram = key($eduPrograms);
		if (count ($eduPrograms) > 1)
		{
			forEach ($eduPrograms as $eid => $e)
			{
				$addParams = ['eduprogram' => intval($eid)];
				$nbt = ['id' => $eid, 'title' => $e['nazev'], 'active' => ($activeEduProgram === $eid), 'addParams' => $addParams];
				$bt [] = $nbt;
			}
			$this->setBottomTabs ($bt);
		}
		else
			$this->addAddParam ('eduprogram', $activeEduProgram);

		parent::init();
	}

	public function renderRow ($item)
	{
		$oddeleni = $this->app()->cfgItem ('e10pro.zus.oddeleni');

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['yearName'];
		$listItem ['t2'] =  $this->app()->cfgItem ("e10pro.zus.obory.{$item ['svpObor']}.zkratka")
                      . ", " .  $oddeleni[$item ['svpOddeleni']]['nazev'] . "  (" . $oddeleni[$item ['svpOddeleni']]['oznaceni'] . ")";

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$this->checkFastSearch ();

		$q [] = "SELECT heads.*, years.nazev as yearName, oddeleni.nazev as oddeleniNazev from [e10pro_zus_teachplanheads] as heads";

		array_push($q, ' LEFT JOIN e10pro_zus_rocniky as years ON heads.[year] = years.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_oddeleni AS oddeleni ON heads.svpOddeleni = oddeleni.ndx');
		array_push($q, ' WHERE 1');

		// -- fulltext
		$fts = $this->fullTextSearch ();
		if ($fts != '')
		{
			foreach($this->fastSearch as $searchValue)
				array_push($q, " AND (years.[nazev] LIKE %s OR years.[zkratka] LIKE %s OR oddeleni.[nazev] LIKE %s)", '%' . $searchValue . '%', '%' . $searchValue . '%', '%' . $searchValue . '%');
		}

			// -- eduProgram
		$eduProgram = $this->bottomTabId ();
		if ($eduProgram !== '')
			array_push ($q, " AND eduprogram = %i", $eduProgram);

		$this->queryMain ($q, 'heads.', ['years.[poradi]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormTeachPlan
 * @package E10Pro\Zus
 */
class FormTeachPlan extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('eduprogram');
			$this->addColumnInput ('svpObor');
			$this->addColumnInput ('svpOddeleni');
			$this->addColumnInput ('year');

			$tabs ['tabs'][] = ['text' => 'Předměty', 'icon' => 'tables/e10pro.zus.predmety'];
			$this->openTabs ($tabs);
				$this->openTab (TableForm::ltNone);
					$this->addList ('rows');
				$this->closeTab();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


