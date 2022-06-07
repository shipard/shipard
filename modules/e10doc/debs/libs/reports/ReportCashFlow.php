<?php

namespace e10doc\debs\libs\reports;

use \e10\uiutils, \e10\utils;
use \e10doc\core\libs\E10Utils;
use \e10doc\debs\libs\AccDataConnector;
use \e10doc\debs\libs\spreadsheets\SpdCashFlow;


/**
 * Class ReportCashFlow
 * @package pkgs\accounting\debs
 */
class ReportCashFlow extends \e10doc\core\libs\reports\GlobalReport
{
	public $fiscalPeriod = 0;
	public $resultFormat = '';
	public $version = '';

	var $cashFlows;
	var $spd;

	function init ()
	{
		$this->cashFlows = $this->app()->cfgItem ('e10.acc.cashFlows');

		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['openclose']]);
		$this->addParamsVersions();
		$this->addParam ('switch', 'resultFormat', ['switch' => ['0' => 'Přesně', '1000' => 'V tisících'], 'radioBtn' => 1]);

		parent::init();

		$this->fiscalPeriod = $this->reportParams ['fiscalPeriod']['value'];
		$this->resultFormat = $this->reportParams ['resultFormat']['value'];
		$this->version = $this->reportParams ['version']['value'];
		if ($this->version === 'auto')
			$this->version = $this->autoDetectVersion();

		$this->setInfo('icon', 'report/CashFlow');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
	}

	function addParamsVersions ()
	{
		
		$enumVersions = [];
		$enumVersions['auto'] = 'Automaticky';
		foreach ($this->cashFlows as $cfId => $cfDef)
		{
			$enumVersions[$cfId] = $cfDef['fullName'];
		}
		$this->addParam ('switch', 'version', ['title' => 'Verze', 'switch' => $enumVersions]);
	}

	function autoDetectVersion ()
	{
		$fp = uiutils::detectParamValue('fiscalPeriod', E10Utils::todayFiscalMonth($this->app()));
		$interval = E10Utils::fiscalPeriodDateInterval($this->app(), $fp, TRUE);
		$date = $interval['begin']->format ('Y-m-d');
		foreach ($this->cashFlows as $cfId => $cfDef)
		{
			if ($cfDef['validFrom'] !== '0000-00-00' && $date < $cfDef['validFrom'])
				continue;
			if ($cfDef['validTo'] !== '0000-00-00' && $date > $cfDef['validTo'])
				continue;

			return $cfId;
		}
		return '';
	}

	function createContent_Report ()
	{
		$variant = $this->cashFlows[$this->version];

		// initStateFiscalPeriod je období poč. stavu aktuálního období
		$dateBeginThis = $this->reportParams ['fiscalPeriod']['values'][$this->reportParams ['fiscalPeriod']['value']]['dateBegin'];
		$fp = E10Utils::todayFiscalYear($this->app(), $dateBeginThis, TRUE);
		$fpBeginDate = utils::createDateTime($fp['begin']);
		$initStateFiscalPeriod = E10Utils::todayFiscalMonth($this->app(), $fpBeginDate, 1);

		// minulé období
		$prevFiscalPeriod = E10Utils::prevFiscalPeriodYear($this->app(), $this->fiscalPeriod);

	  // initStatePrevFiscalPeriod je období poč. stavu minulého období
		$initStatePrevFiscalPeriod = 0;
		if ($prevFiscalPeriod)
		{
			$dateBeginThis = $this->reportParams ['fiscalPeriod']['values'][$prevFiscalPeriod]['dateBegin'];
			$fp = E10Utils::todayFiscalYear($this->app(), $dateBeginThis, TRUE);
			$fpBeginDate = utils::createDateTime($fp['begin']);
			$initStatePrevFiscalPeriod = E10Utils::todayFiscalMonth($this->app(), $fpBeginDate, 1);
		}

		$this->spd = $this->createSpreadsheet();
		$this->spd->spreadsheetId = $variant['spreadsheetId'];
		if (isset($variant['balanceSheetSpreadsheetId']))
			$this->spd->balanceSheetSpreadsheetId = $variant['balanceSheetSpreadsheetId'];
		if (isset($variant['statementSpreadsheetId']))
			$this->spd->statementSpreadsheetId = $variant['statementSpreadsheetId'];
		$this->spd->setParam('fiscalPeriod', $this->fiscalPeriod);
		if ($initStateFiscalPeriod)
			$this->spd->setParam('initStateFiscalPeriod', $initStateFiscalPeriod);
		if ($prevFiscalPeriod)
				$this->spd->setParam('prevFiscalPeriod', $prevFiscalPeriod);
		if ($initStatePrevFiscalPeriod)
				$this->spd->setParam('initStatePrevFiscalPeriod', $initStatePrevFiscalPeriod);
		$this->spd->setParam('resultFormat', $this->resultFormat);
		if ($this->subReportId === 'reportExplain')
			$this->spd->renderAsExplain = TRUE;
		$this->spd->run ();

		$this->addContent ($this->spd->content());

		$this->setInfo('title', $variant['reportTitle']);
		if ($this->resultFormat == 1000)
			$this->setInfo('param', 'Přesnost', 'v tisících');

		$this->setInfo('saveFileName', $variant['reportTitle'].' '.str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']));
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'report': $this->createContent_Report (); break;
			case 'reportExplain': $this->createContent_Report (); break;
		}
	}

	function createSpreadsheet ()
	{
		$spd = new SpdCashFlow($this->app);
		return $spd;
	}

	function dataConnector()
	{
		return new AccDataConnector ($this->app);
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'report', 'icon' => 'detailReportStatement', 'title' => 'Výkaz'];
		$d[] = ['id' => 'reportExplain', 'icon' => 'detailReportAnalysis', 'title' => 'Rozbor'];
		return $d;
	}
}
