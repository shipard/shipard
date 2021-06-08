<?php

namespace e10doc\debs\libs\reports;
use \e10\uiutils, e10doc\core\libs\E10Utils, \e10doc\debs\libs\AccDataConnector;
use \e10doc\debs\libs\spreadsheets\SpdBalanceSheet;

/**
 * Class ReportBalanceSheet
 * @package Pkgs\Accounting\Debs
 */
class ReportBalanceSheet extends \e10doc\core\libs\reports\GlobalReport
{
	public $fiscalPeriod = 0;
	public $resultFormat = '';
	public $version = '';
	public $variant = '';

	var $balanceSheets;
	var $spd;

	var $dataOverview;

	function init ()
	{
		$sr = $this->subReportsList ();
		if ($this->subReportId === '')
			$this->subReportId = $sr[0]['id'];

		$this->balanceSheets = $this->app()->cfgItem ('e10.acc.balanceSheets');

		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['openclose']]);
		$this->addParamsVersions();
		$this->addParam ('switch', 'resultFormat', ['switch' => ['0' => 'Přesně', '1000' => 'V tisících'], 'radioBtn' => 1]);

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

		$this->setInfo('icon', 'report/BalanceSheet');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
	}

	function addParamsVersions ()
	{
		if ($this->subReportId !== 'report' && $this->subReportId !== 'reportExplain')
			return;

		// -- versions
		$enumVersions = [];
		$enumVersions['auto'] = 'Automaticky';
		foreach ($this->balanceSheets as $bsId => $bsDef)
		{
			$enumVersions[$bsId] = $bsDef['fullName'];
		}
		$this->addParam ('switch', 'version', ['title' => 'Verze', 'switch' => $enumVersions]);

		// -- variants
		$enumVariants = [];
		$versionId = uiutils::detectParamValue('version', 'auto');
		if ($versionId === 'auto')
			$versionId = $this->autoDetectVersion();
		foreach ($this->balanceSheets[$versionId]['variants'] as $variantId => $variantDef)
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
		foreach ($this->balanceSheets as $bsId => $bsDef)
		{
			if ($bsDef['validFrom'] !== '0000-00-00' && $date < $bsDef['validFrom'])
				continue;
			if ($bsDef['validTo'] !== '0000-00-00' && $date > $bsDef['validTo'])
				continue;

			return $bsId;
		}
		return '';
	}

	function createContent_Overview ()
	{
		$dataConn = $this->dataConnector();
		$dataConn->resultFormat = $this->resultFormat;
		$dataConn->setAccKinds ([/*0*/$dataConn->ackAssets, /*1*/$dataConn->ackLiabilities, /*5*/$dataConn->ackAssetsLiabilities]); // aktiva, pasiva a aktivně/pasivní
		$dataConn->setParam('fiscalPeriod', $this->fiscalPeriod);
		$dataConn->setParam('resultFormat', $this->resultFormat);

		$ak = ['_'.$dataConn->ackAssets, '_'.$dataConn->ackLiabilities];
		$akNames = ['_'.$dataConn->ackAssets => 'Aktiva', '_'.$dataConn->ackLiabilities => 'Pasiva'];

		$dataConn->init();

		$data = [];
		forEach ($dataConn->allData as $acc)
		{
			$accountId = $acc['accountId'];
			$accountKind = '_'.$acc['accountKind'];
			$data[$accountKind][$accountId] = $acc;
		}

		$totals = $dataConn->kindTotals;

		$all = [];
		$allAccountsSorted = array();
		reset ($ak);
		forEach ($ak as $accountKind)
		{
			if (isset($data[$accountKind]))
				$allAccountsSorted[$accountKind] = \E10\sortByOneKey ($data[$accountKind], 'accountId');
		}

		// -- hospodářský výsledek
		$dataConnStatement = $this->dataConnector();
		$dataConnStatement->setAccKinds ([/*2*/$dataConnStatement->ackExpenses, /*3*/$dataConnStatement->ackRevenues]); // náklady, výnosy
		$dataConnStatement->setParam('fiscalPeriod', $this->fiscalPeriod);
		$dataConnStatement->setParam('resultFormat', $this->resultFormat);
		$dataConnStatement->init();

		$HV = $dataConnStatement->allTotals['ALL'];
		$HV['title'] = 'Hospodářský výsledek';
		$HV['accountId'] = 'HV';
		$HV['HV'] = 1;
		$HV['sumMCr'] -= $HV['sumMDr'];
		$HV['sumYCr'] -= $HV['sumYDr'];
		unset ($HV['initState'], $HV['sumMDr'], $HV['sumYDr']);
		$allAccountsSorted['_'.$dataConnStatement->ackLiabilities][] = $HV;

		reset ($ak);
		forEach ($ak as $accountKind)
		{
			if (!isset ($allAccountsSorted[$accountKind]) || count($allAccountsSorted[$accountKind]) === 0)
				continue;
			$all[] = array ('accountId' => $akNames[$accountKind], '_options' => array ('class' => 'subheader separator', 'colSpan' => array ('accountId' => 8)));
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

			if (!isset($acc['HV']))
			{
				for ($i = 0; $i < 3; $i++)
				{
					if ($totals[$accountKind][$lastSumIds [$i]]['cntRows'] !== 1)
						$all [] = $totals[$accountKind][$lastSumIds [$i]];
				}
			}
			unset ($lastSumIds);

			$totals[$accountKind]['ALL']['accountId'] = 'Σ';
			$totals[$accountKind]['ALL']['title'] = $akNames[$accountKind].' celkem';
			$totals[$accountKind]['ALL']['_options']['class'] = 'sumtotal';

			if (isset($acc['HV']))
			{
				if (isset ($totals[$accountKind]['ALL']['sumMCr']))
					$totals[$accountKind]['ALL']['sumMCr'] += $HV['sumMCr'];
				else
					$totals[$accountKind]['ALL']['sumMCr'] = $HV['sumMCr'];
				if (isset ($totals[$accountKind]['ALL']['sumYCr']))
					$totals[$accountKind]['ALL']['sumYCr'] += $HV['sumYCr'];
				else
					$totals[$accountKind]['ALL']['sumYCr'] = $HV['sumYCr'];
				if (isset ($totals[$accountKind]['ALL']['endState']))
					$totals[$accountKind]['ALL']['endState'] += $HV['endState'];
				else
					$totals[$accountKind]['ALL']['endState'] = $HV['endState'];
			}

			$all [] = $totals[$accountKind]['ALL'];
		}

		$initStateDiff = round($totals['_'.$dataConn->ackAssets]['ALL']['initState'] - $totals['_'.$dataConn->ackLiabilities]['ALL']['initState'], 2);
		$endStateDiff = round($totals['_'.$dataConn->ackAssets]['ALL']['endState'] - $totals['_'.$dataConn->ackLiabilities]['ALL']['endState'], 2);
		if ($initStateDiff != 0.0 || $endStateDiff != 0.0)
		{
			$errRow = ['accountId' => '⚠', 'title' => 'POZOR! rozdíl mezi aktivy a pasivy', '_options' => ['class' => 'e10-error', 'beforeSeparator' => 'separator']];

			if ($initStateDiff != 0.0)
				$errRow ['initState'] = $initStateDiff;
			if ($endStateDiff != 0.0)
				$errRow ['endState'] = $endStateDiff;

			$all [] = $errRow;
		}

		$this->dataOverview = $all;

		$h = array ('accountId' => 'Účet', 'title' => 'Text',
			'initState' => ' Poč. stav',
			'sumMDr' => ' Obrat MD/měsíc', 'sumMCr' => ' Obrat DAL/měsíc',
			'sumYDr' => ' Obrat MD/rok', 'sumYCr' => ' Obrat DAL/rok',
			'endState' => ' Zůstatek');

		$this->addContent (array ('type' => 'table', 'header' => $h,
			'table' => $all, 'main' => TRUE, 'params' => array ('resultFormat' => $this->resultFormat, 'disableZeros' => 1)));

		$this->setInfo('title', 'Rozvaha');
		if ($this->resultFormat == 1000)
			$this->setInfo('param', 'Přesnost', 'v tisících');

		$this->setInfo('saveFileName', 'Rozvaha'.' '.str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']));
		$this->paperOrientation = 'landscape';
	}

	function createContent_Report ()
	{
		$variant = $this->balanceSheets[$this->version]['variants'][$this->variant];

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
		if (isset($variant['statementSpreadsheetId']))
			$this->spd->statementSpreadsheetId = $variant['statementSpreadsheetId'];
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
		$spd = new SpdBalanceSheet ($this->app);
		return $spd;
	}

	function dataConnector()
	{
		return new AccDataConnector ($this->app);
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'overview', 'icon' => 'detailReportAnalytic', 'title' => 'Analyticky'];
		$d[] = ['id' => 'report', 'icon' => 'detailReportStatement', 'title' => 'Výkaz'];
		$d[] = ['id' => 'reportExplain', 'icon' => 'detailReportAnalysis', 'title' => 'Rozbor'];
		return $d;
	}
}
