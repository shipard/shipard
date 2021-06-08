<?php

namespace e10doc\debs\libs\reports;

use \e10\uiutils, e10doc\core\libs\E10Utils;
use \e10doc\debs\libs\spreadsheets\SpdStatement;

/**
 * Class ReportStatement
 
 */
class ReportStatement extends \e10doc\core\libs\reports\GlobalReport
{
	public $fiscalPeriod = 0;
	public $resultFormat = '';

	public $version = '';
	public $variant = '';

	var $statements;
	var $spd;

	var $dataOverview;

	function init ()
	{
		$sr = $this->subReportsList ();
		if ($this->subReportId === '')
			$this->subReportId = $sr[0]['id'];

		$this->statements = $this->app()->cfgItem ('e10.acc.statements');

		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['openclose']]);
		if ($this->app->cfgItem ('options.core.useCentres', 0) && ($this->subReportId == 'overview' || $this->subReportId == ''))
			$this->addParam ('centre');
		$this->addParamsVersions();
		$this->addParam('switch', 'resultFormat', ['switch' => ['0' => 'Přesně', '1000' => 'V tisících'], 'radioBtn' => 1]);

		parent::init();

		if ($this->fiscalPeriod === 0)
			$this->fiscalPeriod = $this->reportParams ['fiscalPeriod']['value'];
		if ($this->resultFormat === '')
			$this->resultFormat = $this->reportParams ['resultFormat']['value'];
		if ($this->subReportId === 'report' || $this->subReportId === 'reportExplain')
		{
			if ($this->version === '')
				$this->version = $this->reportParams ['version']['value'];
			if ($this->version === 'auto')
				$this->version = $this->autoDetectVersion();
			if ($this->variant === '')
				$this->variant = $this->reportParams ['variant']['value'];
		}

		$this->setInfo('icon', 'report/Statement');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		if (isset($this->reportParams ['centre']['activeTitle']) && $this->reportParams ['centre']['activeTitle'] != '-')
			$this->setInfo('param', 'Středisko', $this->reportParams ['centre']['activeTitle']);
	}

	function addParamsVersions ()
	{
		if ($this->subReportId !== 'report' && $this->subReportId !== 'reportExplain')
			return;

		// -- versions
		$enumVersions = [];
		$enumVersions['auto'] = 'Automaticky';
		foreach ($this->statements as $stId => $stDef)
		{
			$enumVersions[$stId] = $stDef['fullName'];
		}
		$this->addParam ('switch', 'version', ['title' => 'Verze', 'switch' => $enumVersions]);

		// -- variants
		$enumVariants = [];
		$versionId = uiutils::detectParamValue('version', 'auto');
		if ($versionId === 'auto')
			$versionId = $this->autoDetectVersion();
		foreach ($this->statements[$versionId]['variants'] as $variantId => $variantDef)
		{
			$enumVariants[$variantId] = $variantDef['name'];
		}
		$this->addParam ('switch', 'variant', ['title' => 'Varianta', 'switch' => $enumVariants]);
	}

	function autoDetectVersion ($fp = FALSE)
	{
		if ($fp === FALSE)
			$fp = uiutils::detectParamValue('fiscalPeriod', E10Utils::todayFiscalMonth($this->app()));

		$interval = E10Utils::fiscalPeriodDateInterval($this->app(), $fp, TRUE);
		$date = $interval['begin']->format ('Y-m-d');
		foreach ($this->statements as $stId => $stDef)
		{
			if ($stDef['validFrom'] !== '0000-00-00' && $date < $stDef['validFrom'])
				continue;
			if ($stDef['validTo'] !== '0000-00-00' && $date > $stDef['validTo'])
				continue;

			return $stId;
		}
		return '';
	}

	function createContent_Overview ()
	{
		$dataConn = $this->dataConnector();
		$dataConn->resultFormat = $this->resultFormat;
		$dataConn->setAccKinds ([/*2*/$dataConn->ackExpenses, /*3*/$dataConn->ackRevenues]); // náklady, výnosy
		$dataConn->setParam('fiscalPeriod', $this->fiscalPeriod);
		$dataConn->setParam('resultFormat', $this->resultFormat);

		$ak = ['_'.$dataConn->ackExpenses, '_'.$dataConn->ackRevenues];
		$akClasses = ['_'.$dataConn->ackExpenses => '5', '_'.$dataConn->ackRevenues => '6'];
		$akNames = ['_'.$dataConn->ackExpenses => 'Náklady', '_'.$dataConn->ackRevenues => 'Výnosy'];

		$dataConn->centre = FALSE;
		if (isset($this->reportParams ['centre']['activeTitle']) && $this->reportParams ['centre']['activeTitle'] != '-')
			$dataConn->centre = $this->reportParams ['centre']['value'];

		$dataConn->init();

		$data = [];
		forEach ($dataConn->allData as $acc)
		{
			$accountId = $acc['accountId'];
			$accountKind = '_'.$acc['accountKind'];
			$data[$accountKind][$accountId] = $acc;
		}

		$totals = $dataConn->kindTotals;

		$all = array ();
		$allAccountsSorted = array();
		reset ($ak);
		forEach ($ak as $accountKind)
		{
			if (isset($data[$accountKind]))
				$allAccountsSorted[$accountKind] = \E10\sortByOneKey ($data[$accountKind], 'accountId');
		}

		reset ($ak);
		forEach ($ak as $accountKind)
		{
			if (!isset ($allAccountsSorted[$accountKind]) || count($allAccountsSorted[$accountKind]) === 0)
				continue;
			$all[] = array ('accountId' => $akNames[$accountKind], '_options' => array ('class' => 'subheader separator', 'colSpan' => array ('accountId' => 4)));
			forEach ($allAccountsSorted[$accountKind] as $acc)
			{
				$accountId = $acc['accountId'];
				$sumIds = array (substr ($accountId, 0, 3), substr ($accountId, 0, 2), substr ($accountId, 0, 1), 'ALL');

				if (isset ($lastSumIds))
				{
					for ($i = 0; $i < 3; $i++)
					{
						if ($lastSumIds [$i] != $sumIds[$i] && ($totals[$accountKind][$lastSumIds [$i]]['cntRows'] > 1 || $i == 2))
							$all [] = $totals[$accountKind][$lastSumIds [$i]];
					}
				}
				else $lastSumIds = array ();

				$all [] = $acc;

				for ($i = 0; $i < 3; $i++)
					$lastSumIds [$i] = $sumIds[$i];
			}

			unset ($lastSumIds);

			$totals[$accountKind]['ALL']['accountId'] = $akClasses[$accountKind];
			$this->setInfo('title', 'Výsledovka');
			$totals[$accountKind]['ALL']['title'] = $akNames[$accountKind].' celkem';

			$all [] = $totals[$accountKind]['ALL'];
		}

		$monthStateDiff = round($totals['_'.$dataConn->ackExpenses]['ALL']['monthState'] + $totals['_'.$dataConn->ackRevenues]['ALL']['monthState'], 2);
		$endStateDiff = round($totals['_'.$dataConn->ackExpenses]['ALL']['endState'] + $totals['_'.$dataConn->ackRevenues]['ALL']['endState'], 2);

		$all[] = array ('accountId' => 'Hospodářský výsledek', '_options' => array ('class' => 'subheader separator', 'colSpan' => array ('accountId' => 4)));
		$resultRow = ['accountId' => 'HV', 'title' => 'Hospodářský výsledek', '_options' => ['class' => 'sumtotal']];

		if ($monthStateDiff != 0.0)
			$resultRow ['monthState'] = $monthStateDiff;
		if ($endStateDiff != 0.0)
			$resultRow ['endState'] = $endStateDiff;

		$all [] = $resultRow;

		$h = array ('accountId' => 'Účet',
			'monthState' => ' Měsíc',
			'endState' => ' Rok',
			'title' => 'Text');

		$this->dataOverview = $all;

		$this->addContent (array ('type' => 'table', 'header' => $h,
			'table' => $all, 'main' => TRUE, 'params' => array ('resultFormat' => $this->resultFormat, 'disableZeros' => 1)));
		$this->setInfo('title', 'Výsledovka');
		if ($this->resultFormat == 1000)
			$this->setInfo('param', 'Přesnost', 'v tisících');

		if (isset($this->reportParams ['centre']['activeTitle']) && $this->reportParams ['centre']['activeTitle'] != '-')
			$this->setInfo('saveFileName', 'Výsledovka'.' '.str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']).' - středisko '.$this->reportParams ['centre']['activeTitle']);
		else
			$this->setInfo('saveFileName', 'Výsledovka'.' '.str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']));
	}

	function createContent_Report ()
	{
		$variant = $this->statements[$this->version]['variants'][$this->variant];

		$prevFiscalPeriod = E10Utils::prevFiscalPeriodYear($this->app(), $this->fiscalPeriod);
		if ($prevFiscalPeriod)
		{
			$prevFiscalPeriodVersion = $this->autoDetectVersion($prevFiscalPeriod);
			//if ($this->version !== $prevFiscalPeriodVersion)
			//	$this->setInfo('note', 'diffVersions', 'UPOZORNĚNÍ: v minulém účetním období platila jiná verze výkazu. Hodnoty minulého účetního období tak nemusí být účetně v pořádku.');
		}

		$this->spd = $this->createSpreadsheet();
		$this->spd->spreadsheetId = $variant['spreadsheetId'];
		if (isset($variant['fullSpreadsheetId']))
			$this->spd->fullSpreadsheetId = $variant['fullSpreadsheetId'];
		$this->spd->setParam('fiscalPeriod', $this->fiscalPeriod);
		if ($prevFiscalPeriod)
			$this->spd->setParam('prevFiscalPeriod', $prevFiscalPeriod);
		$this->spd->setParam('resultFormat', $this->resultFormat);

		if ($this->subReportId === 'reportExplain')
			$this->spd->renderAsExplain = TRUE;

		$this->spd->run ();

		$this->addContent ($this->spd->content());

		$this->setInfo('title', $variant['reportTitle']);
		if ($this->resultFormat == 1000)
			$this->setInfo('param', 'Přesnost', 'v tisících');

		// -- tests notes
		foreach ($this->spd->testNotes as $noteId => $noteText)
			$this->setInfo('note', $noteId, $noteText);

		$this->setInfo('saveFileName', $variant['reportTitle'].' '.str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']));
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'overview': $this->createContent_Overview (); break;
			case 'report': $this->createContent_Report (); break;
			case 'reportExplain': $this->createContent_Report (); break;
		}
	}

	function createSpreadsheet ()
	{
		$spd = new SpdStatement($this->app);
		return $spd;
	}

	function dataConnector()
	{
		return new \e10doc\debs\libs\AccDataConnector($this->app);
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'overview', 'icon' => 'detailReportAnalytic', 'title' => 'Analyticky'];
		$d[] = ['id' => 'report', 'icon' => 'detailReportStatement', 'title' => 'Výkaz'];
		$d[] = ['id' => 'reportExplain', 'icon' => 'detailReportAnalysis', 'title' => 'Rozbor'];
		return $d;
	}
}
