<?php

namespace pkgs\accounting\debs;


use \E10\utils, E10Doc\Core\e10utils;


/**
 * Class ShareVatReturn
 * @package E10Doc\CmnBkp
 */
class FinalAccountsShareEngine extends \e10\share\ShareEngine
{
	var $fiscalYearNdx;
	var $fiscalYear;
	var $fiscalMonthNdx;

	var $tableHeads;
	var $tableJournal;

	var $enabledDocsTypes = ['invni', 'invno', 'cashreg', 'cash'];

	public function init()
	{
		parent::init();
		$this->classId = 'pkgs.accounting.debs.FinalAccountsShareEngine';
		$this->tableHeads = $this->app->table('e10doc.core.heads');
		$this->tableJournal = $this->app->table('e10doc.debs.journal');

		$this->fiscalYearNdx = intval($this->params['fiscalYear']);
		$this->fiscalYear = $this->app->cfgItem ('e10doc.acc.periods.'.$this->fiscalYearNdx, FALSE);

		$this->fiscalMonthNdx = e10utils::todayFiscalMonth ($this->app, $this->fiscalYear['end']);
	}

	public function actionName()
	{
		return 'Sdílení Účetní závěrky';
	}

	public function actionParams()
	{
		$params = [
			['name' => 'Účetní období', 'id' => 'fiscalYear', 'type' => 'fiscalYear']
		];

		return $params;
	}


	public function createShareHeader ()
	{
		$this->shareRecData['name'] = 'Účetní závěrka '.$this->fiscalYear['fullName'];
		$this->shareRecData['shareType'] = 2;
		$this->shareRecData['tableId'] = 'e10doc.core.heads';
		$this->shareRecData['recId'] = $this->coreParams['srcNdx'];

		parent::createShareHeader();

		$this->addFolder ('reports', 'Přehledy');
		$this->addFolder ('balances', 'Saldokonta');
		$this->addFolder ('accounts', 'Účty');
	}

	public function addReportsAccounts ()
	{
		// -- general ledger
		$report = $this->app->createObject('e10doc.debs.libs.reports.GeneralLedger');
		$report->setParamsValues(['fiscalPeriod' => $this->fiscalMonthNdx]);
		$report->fiscalYear = $this->fiscalYearNdx;
		$report->format = 'pdf';
		$report->init();
		$this->addReport($report, 'Hlavní kniha', 'hlavni-kniha', 'reports');

		// accounts / journal
		foreach ($report->accounts as $accountId => $accountName)
		{
			$viewer = $this->tableJournal->getTableView ('default');
			$viewer->fiscalPeriod = 'Y'.$this->fiscalYearNdx;
			$viewer->accountId = $accountId;
			$fileName = $viewer->saveViewerData ();
			$this->addViewerReport ($fileName, $accountName, $accountId, 'accounts');
		}
	}

	public function addReportsBalances ()
	{
		$this->addReportsBalance('e10doc.balance.reportBalanceReceivables', 'Pohledávky', 'pohledavky');
		$this->addReportsBalance('e10doc.balance.reportBalanceObligations', 'Závazky', 'zavazky');

		$this->addReportsBalance('e10doc.balance.reportBalanceDepositReceived', 'Přijaté zálohy', 'prijate-zalohy');
		$this->addReportsBalance('e10doc.balance.reportBalanceAdvance', 'Poskytnuté zálohy', 'poskytnute-zalohy');

		$this->addReportsBalance('e10doc.balance.reportBalanceCashInTransit', 'Peníze na cestě', 'penize-na-ceste');
	}

	public function addReportsBalance ($class, $name, $id)
	{
		$balanceReport = $this->app->createObject($class);
		if ($class === 'e10doc.balance.reportBalanceReceivables' || $class === 'e10doc.balance.reportBalanceObligations')
			$balanceReport->setParamsValues(['fiscalPeriod' => 'Y'.$this->fiscalYearNdx]);
		else
			$balanceReport->setParamsValues(['fiscalYear' => $this->fiscalYearNdx]);
		$balanceReport->format = 'pdf';
		$balanceReport->init();

		$balanceKinds = $balanceReport->balanceKinds;
		foreach ($balanceKinds as $balanceKindId => $balanceKindName)
		{
			if ($balanceKindId == 0)
				continue;

			$report = $this->app->createObject($class);
			if ($class === 'e10doc.balance.reportBalanceReceivables' || $class === 'e10doc.balance.reportBalanceObligations')
				$report->setParamsValues(['fiscalPeriod' => 'Y'.$this->fiscalYearNdx, 'balanceKind' => $balanceKindId]);
			else
				$report->setParamsValues(['fiscalYear' => $this->fiscalYearNdx, 'balanceKind' => $balanceKindId]);
			$report->format = 'pdf';
			$report->init();
			$this->addReport($report, $name . ' ' . $balanceKindId, $id, 'balances', $balanceKindName);
		}
	}

	public function addReports ()
	{
		$this->addReportsAccounts();
		$this->addReportsBalances();

		// ReportStatement
		$report = $this->app->createObject('pkgs.accounting.debs.ReportStatement');
		$report->setParamsValues(['fiscalPeriod' => $this->fiscalMonthNdx, 'resultFormat' => '0']);
		$report->format = 'pdf';
		$report->init();
		$this->addReport($report, 'Výsledovka', 'vysledovka', 'reports');

		// ReportBalanceSheet
		$report = $this->app->createObject('pkgs.accounting.debs.ReportBalanceSheet');
		$report->setParamsValues(['fiscalPeriod' => $this->fiscalMonthNdx, 'resultFormat' => '0']);
		$report->format = 'pdf';
		$report->init();
		$this->addReport($report, 'Rozvaha', 'rozvaha', 'reports');

		// ReportStatementFull
		$report = $this->app->createObject('pkgs.accounting.debs.ReportStatement');
		$report->setParamsValues(['fiscalPeriod' => $this->fiscalMonthNdx, 'resultFormat' => '0']);
		$report->subReportId = 'full';
		$report->format = 'pdf';
		$report->init();
		$this->addReport($report, 'Výkaz zisku a ztrát', 'vykaz-zisku-a-ztrat', 'reports');

		// ReportBalanceSheetFull
		$report = $this->app->createObject('pkgs.accounting.debs.ReportBalanceSheet');
		$report->setParamsValues(['fiscalPeriod' => $this->fiscalMonthNdx, 'resultFormat' => '0']);
		$report->subReportId = 'full';
		$report->format = 'pdf';
		$report->init();
		$this->addReport($report, 'Rozvaha v úplném rozsahu', 'rozvaha-v-uplnem-rozsahu', 'reports');
	}

	public function run ()
	{
		$this->createShareHeader();
		$this->addReports();
		$this->saveFoldersCounts();
		$this->done();
	}
}
