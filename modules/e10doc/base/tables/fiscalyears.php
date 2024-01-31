<?php

namespace E10Doc\Base;


use \E10\Application, \E10\utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;


/**
 * Fiskální období - roční
 *
 */

class TableFiscalYears extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.base.fiscalyears", "e10doc_base_fiscalyears", "Fiskální období - roční");
	}

	public function createYear ($year)
	{
		$firstMonth = $this->app()->cfgItem ('options.core.firstFiscalYearMonth', FALSE);
		if ($firstMonth === FALSE || $firstMonth == 99)
			return 0;

		$sql = "SELECT * FROM [e10doc_base_fiscalyears] WHERE docState != 9800 AND YEAR(start) = %i";
		$rows = $this->app()->db->query ($sql, intval($year))->fetch ();
		if ($rows)
		{
			return 0;
		}

		$lastYear = $this->app()->db->query ('SELECT * FROM [e10doc_base_fiscalyears] WHERE docState != 9800 AND YEAR(start) < %i', intval($year), ' ORDER BY [start] DESC')->fetch ();
		if ($lastYear)
		{
			$currency = $lastYear['currency'];
			$accMethod = $lastYear['accMethod'];
			$stockAccMethod = $lastYear['stockAccMethod'];
			$propertyDepsMethod = $lastYear['propertyDepsMethod'];
		}
		else
		{
			$currency = 'czk';
			$accMethod = 'debs';
			$stockAccMethod = 'stockOff';
			$propertyDepsMethod = 'propDepsY';
		}
		$startDate = new \DateTime ("$year-$firstMonth-01");

		$nextYear = $year + 1;
		$nextPeriodBegin = "$nextYear-$firstMonth-01";
		$endDate = new \DateTime (date ('Y-m-d', strtotime('-1 day', strtotime($nextPeriodBegin))));

		$endMonth = $endDate->format('m');
		$endDay = $endDate->format('d');

		// -- year
		$newYear = [
			'fullName' => "$year", 'mark' => $startDate->format('y'),
			'accMethod' => $accMethod, 'stockAccMethod' => $stockAccMethod, 'propertyDepsMethod' => $propertyDepsMethod,
			'currency' => $currency,
			'start' => $startDate, 'end' => $endDate, 'docState' => 4000, 'docStateMain' => 2
		];
		if ($firstMonth != 1)
			$newYear['fullName'] .= '/'.$endDate->format('y');

		$this->app()->db->query ("INSERT INTO [e10doc_base_fiscalyears]", $newYear);
		$newYearNdx = $this->app()->db->getInsertId ();

		// -- months
		$month = 0;
		$newMonth = array ('fiscalYear' => $newYearNdx, 'fiscalType' => 1,
											 'calendarYear' => $year, 'calendarMonth' => $month,
											 'globalOrder' => intval(utils::createDateTime("$year-$firstMonth-01")->format("Y1md")),
											 'start' => "$year-$firstMonth-01", 'end' => "$year-$firstMonth-01");
		$this->app()->db->query ("INSERT INTO [e10doc_base_fiscalmonths]", $newMonth);

		$month = $firstMonth;
		$localOrder = 1;
		for ($i = 0; $i < 12; $i++)
		{
			$startDateStr = sprintf ('%04d-%02d-01', $year, $month);
			$startDate = new \DateTime ($startDateStr);
			$endDateStr = $startDate->format ('Y-m-t');

			$newMonth = array ('fiscalYear' => $newYearNdx, 'fiscalType' => 0,
												 'calendarYear' => $year, 'calendarMonth' => $month,
												 'localOrder' => $localOrder,
												 'globalOrder' => intval(utils::createDateTime($startDateStr)->format("Y2md")),
												 'start' => $startDateStr, 'end' => $endDateStr);

			$this->app()->db->query ("INSERT INTO [e10doc_base_fiscalmonths]", $newMonth);

			$month++;
			if ($month == 13)
			{
				$month = 1;
				$year++;
			}
			$localOrder++;
		}

		if ($month === 1)
			$year--;
		$month = 13;
		$newMonth = array ('fiscalYear' => $newYearNdx, 'fiscalType' => 2,
											 'calendarYear' => $year, 'calendarMonth' => $month,
											 'localOrder' => $localOrder,
											 'globalOrder' => intval(utils::createDateTime("$year-$endMonth-$endDay")->format("Y2md")),
											 'start' => "$year-$endMonth-$endDay", 'end' => "$year-$endMonth-$endDay");
		$this->app()->db->query ("INSERT INTO [e10doc_base_fiscalmonths]", $newMonth);

		return 1;
	}

	public function saveConfig ()
	{
		$now = new \DateTime ();
		$year =	intval ($now->format ('Y'));
		$this->createYear ($year);
		$this->createYear ($year + 1);

		// -- save config
		$accPeriods = [];
		$accUsedMethods = [];
		$rows = $this->app()->db->query ("SELECT * from [e10doc_base_fiscalyears] WHERE docState != 9800 AND [start] IS NOT NULL AND [end] IS NOT NULL ORDER BY [start]");

		$prevNdx = 0;

		foreach ($rows as $r)
		{
			$accPeriods [$r['ndx']] = [
				'ndx' => $r ['ndx'], 'mark' => $r ['mark'], 'currency' => $r ['currency'],
				'method' => $r['accMethod'], 'stockAccMethod' => $r['stockAccMethod'], 'propertyDepsMethod' => $r['propertyDepsMethod'],
				'fullName' => $r ['fullName'],
				'begin' => $r['start']->format('Y-m-d'), 'end' => $r['end']->format('Y-m-d'), 'prevNdx' => $prevNdx,
				'disableCheckOpenStates' => $r['disableCheckOpenStates'],
			];

			$prevNdx = $r['ndx'];

			if ($r['accMethod'] === 'none')
				continue;
			if (!isset ($accUsedMethods[$r['accMethod']]))
				$accUsedMethods[$r['accMethod']] = 1;
		}

		// -- save to file
		$cfg ['e10doc']['acc']['periods'] = $accPeriods;
		$cfg ['e10doc']['acc']['usedMethods'] = $accUsedMethods;
		if (count($accUsedMethods))
			$cfg ['e10doc']['acc']['use'] = 1;

		file_put_contents(__APP_DIR__ . '/config/_e10doc.acc.json', utils::json_lint (json_encode ($cfg)));
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['info'][] = array ('class' => 'title', 'value' => $recData ['fullName']);

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{ // TODO: remove in future
		if (isset($recData['ndx']) && $recData['ndx'] != 0 && isset($recData['docState']) && ($recData['docState'] == 0))
		{
			$recData['docState'] = 1000;
			$recData['docStateMain'] = 0;
		}
		parent::checkBeforeSave ($recData, $ownerData);
	}
} // class TableFiscalYears


/**
 * Class ViewFiscalYears
 * @package E10Doc\Base
 *
 * Prohlížeč Účetních období (Fiskálních roků)
 */
class ViewFiscalYears extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_base_fiscalyears]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([fullName] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[start] DESC', '[ndx] DESC']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = ['text' => $item['fullName'], 'suffix' => utils::datef ($item['start']) . ' - ' . utils::datef ($item['end'])];

		$currencies = $this->table->columnInfoEnum ('currency', 'cfgText');
		$accMethods = $this->table->columnInfoEnum ('accMethod', 'cfgText');
		$listItem ['i1'] = $accMethods [$item ['accMethod']].' / '.$currencies [$item ['currency']];

		$stockAccMethods = $this->table->columnInfoEnum ('stockAccMethod', 'cfgText');
		$propertyDepsMethods = $this->table->columnInfoEnum ('propertyDepsMethod', 'cfgText');
		$listItem ['t2'] = [
			['text' => $stockAccMethods[$item ['stockAccMethod']], 'icon' => 'icon-cubes'],
			['text' => $propertyDepsMethods[$item ['propertyDepsMethod']], 'icon' => 'icon-sort-amount-desc']
		];

		return $listItem;
	}

	public function queryMain (&$q, $tablePrefix = '', $order = NULL, $forceArchive = FALSE)
	{
		$mainQuery = $this->mainQueryId ();
		// -- active
		if ($mainQuery === 'active' || $mainQuery === '')
			array_push ($q, " AND ({$tablePrefix}[docStateMain] < 4 OR {$tablePrefix}[docStateMain] = 5)");

		// -- archive
		if ($mainQuery === 'archive')
			array_push ($q, " AND {$tablePrefix}[docStateMain] = 5");

		// trash
		if ($mainQuery === 'trash')
			array_push ($q, " AND {$tablePrefix}[docStateMain] = 4");

		if ($order !== NULL)
			array_push ($q, ' ORDER BY ', implode(', ', $order), $this->sqlLimit ());
	}
}


/*
 * FormFiscalYears
 *
 */

class FormFiscalYears extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

    $tabs ['tabs'][] = array ('text' => 'Měsíce', 'icon' => 'formMonths');

		$this->openForm ();

		$this->layoutOpen (TableForm::ltHorizontal);
			$this->layoutOpen (TableForm::ltForm);
				$this->addColumnInput ('fullName');
				$this->addColumnInput ('mark');
				$this->addColumnInput ('start');
				$this->addColumnInput ('end');
			$this->layoutClose ('width50');

			$this->layoutOpen (TableForm::ltForm);
				$this->addColumnInput ("currency");
				$this->addColumnInput ('accMethod');
				$this->addColumnInput ('stockAccMethod');
				$this->addColumnInput ('propertyDepsMethod');
				$this->addColumnInput ('disableCheckOpenStates');

			$this->layoutClose ('width50');
		$this->layoutClose ();

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addList ('rows');
				$this->closeTab ();
			$this->closeTabs ();

    $this->closeForm ();
	}
} // class FormFiscalYears

