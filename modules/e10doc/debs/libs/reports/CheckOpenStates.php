<?php

namespace e10doc\debs\libs\reports;
use e10doc\core\libs\E10Utils;


/**
 * Class CheckOpenStates
 */
class CheckOpenStates extends \e10doc\core\libs\reports\GlobalReport
{
	var $fiscalYear = 0;
	var $fiscalPeriod = 0;

	var $prevFiscalYear = 0;
	var $prevFiscalPeriod = 0;

	var $fiscalYearCfg;
	var $prevFiscalYearCfg;

	/** @var \e10doc\debs\libs\reports\GeneralLedger */
	var $glThisPeriod;
	/** @var \e10doc\debs\libs\reports\GeneralLedger */
	var $glPrevPeriod;

	function init()
	{
		if ($this->subReportId === '')
			$this->subReportId = 'all';

		if ($this->subReportId === 'period')
			$this->addParam('fiscalYear', 'fiscalYear', []);

		parent::init();

		$this->paperOrientation = 'landscape';
	}

	function createContent ()
	{
		if ($this->subReportId === 'period')
			$this->createContent_OnePeriod();
		else
			$this->createContent_AllPeriods();
	}

	function createContent_OnePeriod ()
	{
		if ($this->fiscalYear === 0)
			$this->fiscalYear = $this->reportParams ['fiscalYear']['value'];
		$this->fiscalPeriod = E10Utils::yearFirstFiscalMonth($this->app(), $this->fiscalYear);

		$this->prevFiscalYear = E10Utils::prevFiscalYear($this->app(), $this->fiscalYear);
		$this->prevFiscalPeriod = E10Utils::yearLastFiscalMonth($this->app(), $this->prevFiscalYear);

		$this->fiscalYearCfg = $this->app->cfgItem('e10doc.acc.periods.' . $this->fiscalYear);
		$this->prevFiscalYearCfg = $this->app->cfgItem('e10doc.acc.periods.' . $this->prevFiscalYear);

		$this->setInfo('title', 'Kontrola počátečních stavů');
		$this->setInfo('icon', 'reportControlInitialStates');


		$this->createContent_DoOnePeriod();
	}

	function createContent_DoOnePeriod ()
	{
		$this->fiscalPeriod = E10Utils::yearFirstFiscalMonth($this->app(), $this->fiscalYear);

		$this->prevFiscalYear = E10Utils::prevFiscalYear($this->app(), $this->fiscalYear);
		$this->prevFiscalPeriod = E10Utils::yearLastFiscalMonth($this->app(), $this->prevFiscalYear);

		$this->fiscalYearCfg = $this->app->cfgItem('e10doc.acc.periods.' . $this->fiscalYear);
		$this->prevFiscalYearCfg = $this->app->cfgItem('e10doc.acc.periods.' . $this->prevFiscalYear);

		$diffsOnly = 1;

		if ($this->subReportId === 'period')
		{
			$this->setInfo('title', 'Kontrola počátečních stavů');
			$this->setInfo('icon', 'reportControlInitialStates');
			$this->setInfo('param', 'Období', $this->prevFiscalYearCfg['fullName'].' → '.$this->fiscalYearCfg['fullName']);
			$this->setInfo('saveFileName', 'Kontrola počátečních stavů' . str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']));
			$diffsOnly = 0;
		}

		$this->glThisPeriod = new \e10doc\debs\libs\reports\GeneralLedger($this->app());
		$this->glThisPeriod->fiscalYear = $this->fiscalYear;
		$this->glThisPeriod->fiscalPeriod = $this->fiscalPeriod;
		$this->glThisPeriod->init();
		$this->glThisPeriod->createContent();

		$this->glPrevPeriod = new \e10doc\debs\libs\reports\GeneralLedger($this->app());
		$this->glPrevPeriod->fiscalYear = $this->prevFiscalYear;
		$this->glPrevPeriod->fiscalPeriod = $this->prevFiscalPeriod;
		$this->glPrevPeriod->init();
		$this->glPrevPeriod->createContent();

		$table = [];
		foreach ($this->glThisPeriod->dataAll as $acc)
		{
			$accId = $acc['accountId'];

			if ($acc['accGroup'] || (isset($acc['accountKind']) && $acc['accountKind'] > 1))
				continue;
			$item = [
				'accountId' => $acc['accountId'],
				'initState' => isset($acc['initState']) ? $acc['initState'] : 0.0,
				'endState' => 0.0,
				'title' => $acc['title'],
			];

			$table[$accId] = $item;
		}

		$totalsByKind = [];
		foreach ($this->glPrevPeriod->dataAll as $acc)
		{
			$accId = $acc['accountId'];
			if ($acc['accGroup'])
				continue;
			if (!isset($acc['accountKind']))
			{
				continue;
			}

			if (isset($totalsByKind[$acc['accountKind']]))
				$totalsByKind[$acc['accountKind']] += $acc['endState'];
			else
				$totalsByKind[$acc['accountKind']] = $acc['endState'];

			if ($acc['accountKind'] > 1)
				continue;

			if (isset($table[$accId]))
			{
				$table[$accId]['endState'] = $acc['endState'];
				continue;
			}

			$item = [
				'accountId' => $acc['accountId'],
				'initState' => 0.0,
				'endState' => isset($acc['endState']) ? $acc['endState'] : 0.0,
				'title' => $acc['title'],
			];

			$table[$accId] = $item;
		}

		foreach ($table as &$r)
		{
			if (substr($r['accountId'], 0, 3) === '431' || substr($r['accountId'], 0, 3) === '931')
				$r['endState'] = ($totalsByKind[2] ?? 0.0) + ($totalsByKind[3] ?? 0.0);

			$r['diff'] = round($r['endState'] - $r['initState'], 2);

			if (round($r['initState'], 2) !== round($r['endState'], 2))
			{
				if ($this->testEngine)
					$r['_options']['cellClasses'] = ['diff' => 'e10-warning1'];
				else
					$r['_options']['class'] = 'e10-warning3';

				$r['isDiff'] = 1;
			}
		}

		if ($diffsOnly)
		{
			$newTable = [];
			foreach ($table as $r)
			{
				if (!isset($r['isDiff']))
					continue;
				$newTable[] = $r;
			}
			$table = $newTable;
		}

		$table = \e10\sortByOneKey($table, 'accountId');

		$h = [
			'accountId' => 'Účet',
			'endState' => ' Kon. zůst. '.$this->prevFiscalYearCfg['fullName'],
			'initState' => ' Poč. stav '.$this->fiscalYearCfg['fullName'],
			'diff' => '+Rozdíl',
			'title' => 'Text',
		];

		$content = ['type' => 'table', 'header' => $h, 'table' => $table, 'main' => TRUE];
		if ($this->subReportId === 'all')
		{
			$content['title'] = $this->prevFiscalYearCfg['fullName'].' → '.$this->fiscalYearCfg['fullName'];
			if (intval($this->fiscalYearCfg['disableCheckOpenStates'] ?? 0))
				$content['title'] .= ' (nekontroluje se)';
		}

		if (count($table))
		{
			$this->addContent($content);

			if ($this->testEngine && !intval($this->fiscalYearCfg['disableCheckOpenStates'] ?? 0))
			{
				$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => $this->prevFiscalYearCfg['fullName'].' → '.$this->fiscalYearCfg['fullName'], 'class' => 'h2 block pt1']]);
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $table]);
			}
		}
		else
		{
			$this->addContent(['type' => 'line', 'line' => [
					['text' => $this->prevFiscalYearCfg['fullName'].' → '.$this->fiscalYearCfg['fullName'], 'class' => 'subtitle block'],
					['text' => 'Nebyly zjištěny žádné rozdíly', 'class' => 'e10-off']
				]
			]);
		}
	}

	function createContent_AllPeriods ()
	{
		$this->setInfo('title', 'Kontrola počátečních stavů');
		$this->setInfo('icon', 'reportControlInitialStates');

		$this->setInfo('title', 'Kontrola počátečních stavů');


		$allFiscalPeriods = $this->app->cfgItem('e10doc.acc.periods');

		$firstFP = NULL;
		$lastFP = NULL;
		//$lastFPId = array_key_last ($allFiscalPeriods); TODO: PHP 7.3
		$lastFPId = key(array_slice($allFiscalPeriods, -1, NULL, TRUE));

		$counter = 0;
		foreach ($allFiscalPeriods as $fpId => $fp)
		{
			if ($fp['method'] === 'none')
				continue;

			if ($firstFP === NULL)
				$firstFP = $fp;

			$counter++;
			if ($counter === 1 || $fpId === $lastFPId)
				continue;

			$lastFP = $fp;

			$this->fiscalYear = intval($fpId);
			$this->createContent_DoOnePeriod();
		}

		if ($firstFP && $lastFP)
		{
			$this->setInfo('param', 'Období', $firstFP['fullName'] . ' → ' . $lastFP['fullName']);
			$this->setInfo('saveFileName', 'Kontrola návaznosti počátečních stavů');
		}
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'all', 'icon' => 'detailReportAll', 'title' => 'Vše'];
		$d[] = ['id' => 'period', 'icon' => 'detailReportPeriod', 'title' => 'Období'];

		return $d;
	}

	public function setTestCycle ($cycle, $testEngine)
	{
		parent::setTestCycle($cycle, $testEngine);
		$this->subReportId = 'all';
	}

	public function testTitle ()
	{
		$t = [];
		$t[] = [
			'text' => 'Problémy s návazností počátečních stavů',
			'class' => 'subtitle e10-me h1 block mt1 bb1 lh16'
		];
		return $t;
	}
}
