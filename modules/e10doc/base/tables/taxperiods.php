<?php

namespace E10Doc\Base;


use \E10\Application, \E10\utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;

class TableTaxPeriods extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.base.taxperiods", "e10doc_base_taxperiods", "Daňová období");
	}

	public function createPeriod ($year, $vatReg = 1)
	{
		$vatPeriod = $this->app()->cfgItem ('options.core.vatPeriod', 0);
		if ($vatPeriod == 0)
			return 0;

		$vatRegCfg = $this->app()->cfgItem ('e10doc.base.taxRegs.vat.'.$vatReg, NULL);
		if (!$vatRegCfg)
			return 0;

		$vatPeriod = $vatRegCfg['periodType'];

		$rows = $this->app()->db->query ('SELECT * FROM [e10doc_base_taxperiods] WHERE YEAR(start) = %i', intval($year),
			' AND [vatReg] = %i', $vatReg)->fetch ();
		if ($rows)
			return 0;

		if ($vatPeriod == 1)
		{ // monthly
			$month = 1;

			for ($i = 0; $i < 12; $i++)
			{
				$startDateStr = sprintf ('%04d-%02d-01', $year, $month);
				$startDate = new \DateTime ($startDateStr);
				$endDateStr = $startDate->format ('Y-m-t');

				$newVatPeriod = array ('fullName' => "$year/$month", 'id' => sprintf ('%04d/%02d', $year, $month),
															 'periodType' => 0, 'start' => $startDateStr, 'end' => $endDateStr,
															 'vatReg' => $vatReg, 'docState' => 4000, 'docStateMain' => 2);

				$this->app()->db->query ("INSERT INTO [e10doc_base_taxperiods]", $newVatPeriod);

				$month++;
			}
		}
		else
		if ($vatPeriod == 2)
		{ // quarterly
			$month = 1;

			for ($i = 1; $i <= 4; $i++)
			{
				$startDateStr = sprintf ('%04d-%02d-01', $year, $month);
				$startDate = new \DateTime ($startDateStr);

				$endMonth = $month + 2;
				$endDateStr = sprintf ('%04d-%02d-01', $year, $endMonth);
				$endDate = new \DateTime ($endDateStr);
				$endDateStr = $endDate->format ('Y-m-t');

				$newVatPeriod = array ('fullName' => "$year/{$i}Q", 'id' => "$year/{$i}Q",
															 'periodType' => 0, 'start' => $startDateStr, 'end' => $endDateStr,
															 'vatReg' => $vatReg, 'docState' => 4000, 'docStateMain' => 2);

				$this->app()->db->query ("INSERT INTO [e10doc_base_taxperiods]", $newVatPeriod);

				$month += 3;
			}
		}

		return 1;
	}

	public function saveConfig ()
	{
		$now = new \DateTime ();
		$year =	intval ($now->format ('Y'));

		$vatRegs = $this->app()->cfgItem ('e10doc.base.taxRegs.vat', []);
		foreach ($vatRegs as $vr)
		{
			$this->createPeriod($year, $vr['ndx']);
			$this->createPeriod($year + 1, $vr['ndx']);
		}

		$vatPeriods = array ();
		$rows = $this->app()->db->query ("SELECT * from [e10doc_base_taxperiods] WHERE docState != 9800 AND [start] IS NOT NULL AND [end] IS NOT NULL ORDER BY [start]");

		foreach ($rows as $r)
		{
			$vatPeriods [$r['ndx']] = [
					'ndx' => $r ['ndx'], 'id' => $r ['id'], 'vatReg' => $r['vatReg'],
					'fullName' => $r ['fullName'], 'begin' => $r['start']->format('Y-m-d'), 'end' => $r['end']->format('Y-m-d')
			];
		}

		// save to file
		$cfg ['e10doc']['vatPeriods'] = $vatPeriods;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.vatPeriods.json', utils::json_lint (json_encode ($cfg)));
	}
} // class TableTaxPeriods


/**
 * Class ViewTaxPeriods
 * @package E10Doc\Base
 */
class ViewTaxPeriods extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;

		$mq [] = array ('id' => 'active', 'title' => 'Otevřené období');
		$mq [] = array ('id' => 'closed', 'title' => 'Uzavřeno');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		$active = 1;
		$vatRegs = $this->app()->cfgItem ('e10doc.base.taxRegs.vat', []);
		forEach ($vatRegs as $ndx => $vatReg)
		{
			$addParams = ['vatReg' => $ndx];
			$nbt = ['id' => $ndx, 'title' => $vatReg['taxId'], 'active' => $active, 'addParams' => $addParams];
			$bt [] = $nbt;

			$active = 0;
		}
		$this->setBottomTabs ($bt);

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$vatReg = intval($this->bottomTabId ());

		$q [] = "SELECT * from [e10doc_base_taxperiods] WHERE 1";

		if ($vatReg)
			array_push ($q, ' AND [vatReg] = %i', $vatReg);

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [id] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery === 'active' || $mainQuery === '')
			array_push ($q, " AND [docStateMain] < 4");

		// closed
		if ($mainQuery === 'closed')
			array_push ($q, " AND [docStateMain] = 5");

		// trash
		if ($mainQuery === 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		if ($mainQuery === 'all')
			array_push ($q, ' ORDER BY [start] DESC, [fullName], [ndx] ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY [docStateMain], [start] DESC, [fullName], [ndx] ' . $this->sqlLimit ());


		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t2'] = \E10\df ($item['start'], '%D') . ' - ' . \E10\df ($item['end'], '%D');
		
		return $listItem;
	}
}


/**
 * Class FormTaxPeriods
 * @package E10Doc\Base
 */
class FormTaxPeriods extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ("fullName");
			$this->addColumnInput ("id");
			$this->addColumnInput ("periodType");
			$this->addColumnInput ("start");
			$this->addColumnInput ("end");
			$this->addColumnInput ('vatReg');
		$this->closeForm ();
	}	

	public function createHeaderCode ()
	{
		$item = $this->recData;
		$info = $item ['id'];
		return $this->defaultHedearCode ('x-cog', $item ['fullName'], $info);
	}
}

