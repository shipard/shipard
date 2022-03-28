<?php

namespace e10doc\taxes\TaxCI;
use \Shipard\Utils\Utils, e10doc\core\libs\E10Utils;


/**
 * Class TaxCIEngine
 * @package e10doc\taxes\TaxCI
 */
class TaxCIDataCreator extends \e10doc\taxes\TaxReportDataCreator
{
	public function init ()
	{
		$this->taxReportId = 'cz-tax-ci';
		parent::init();
	}

	public function rebuild()
	{
		parent::rebuild();

		$this->createAll();
		$this->saveData();
		$this->resetParts();
	}

	function createAll ()
	{
		$dates = ['begin'];
		if (!Utils::dateIsBlank($this->reportRecData['datePeriodBegin']))
			$dates['begin'] = $this->reportRecData['datePeriodBegin']->format('Y-m-d');
		if (!Utils::dateIsBlank($this->reportRecData['datePeriodEnd']))
			$dates['end'] = $this->reportRecData['datePeriodEnd']->format('Y-m-d');
		$this->setItem ('dates', $dates);

		$this->createPropertyDepreciations();
		$this->createNonTaxCosts();
		$this->createBalanceSheet(FALSE);
		$this->createBalanceSheet(TRUE);
		$this->createStatement(FALSE);
		$this->createStatement(TRUE);
		$this->createGeneralLedger();
	}

	function createPropertyDepreciations ()
	{
		$report = new \e10pro\property\ReportDepreciations($this->app());
		$report->groupBy = 'depsGroups';
		$report->fiscalYear = $this->reportRecData['accPeriod'];
		$report->init();
		$report->loadData();
		$report->createContent_Sum();

		$data = [];
		$taxDepsTotal = 0;
		foreach ($report->groupByDepsGroups as $groupId => $groupData)
		{
			$dg = $report->depsGroups[$groupId];
			$colId = (isset($dg['taxDepsTaxCI'])) ? $dg['taxDepsTaxCI'] : FALSE;
			if (!$colId)
				continue;

			if ($groupData['totals']['taxUsed'] != 0.0)
			{
				if (!isset($data[$colId]))
					$data[$colId] = 0.0;

				$data[$colId] += $groupData['totals']['taxUsed'];
				$taxDepsTotal += $groupData['totals']['taxUsed'];
			}
		}
		$data['taxDepsTotal'] = $taxDepsTotal;
		$data['diffTaxAcc'] = intval(round($report->totals['all']['diff'], 0));
		$this->setItem ('propertyDeps', $data);
	}

	function createNonTaxCosts ()
	{
		$q[] = 'SELECT journal.accountId, accounts.shortName as accountName, SUM(journal.money) as sumY, SUM(journal.moneyDr) as sumYDr, SUM(journal.moneyCr) as sumYCr FROM e10doc_debs_journal as journal ';
		array_push ($q, 'LEFT JOIN e10doc_debs_accounts as accounts ON journal.accountId = accounts.id');
		array_push ($q, ' WHERE fiscalType IN (0, 2) AND fiscalYear = %i', $this->reportRecData['accPeriod']);
		array_push ($q, ' AND accounts.accountKind = %i', 2);
		array_push ($q, ' AND accounts.nontax = %i', 1);
		array_push ($q, ' GROUP BY accountId');
		array_push ($q, ' ORDER BY accountId');

		$data = [];
		$rows = $this->app->db()->query($q);
		$total = 0;
		$idx = 0;
		forEach ($rows as $r)
		{
			if ($r['sumYDr'] == 0.0)
				continue;

			$item = [
				'kc_1a' => intval(round($r['sumYDr'], 0)),
				'naz_uc_skup' => $r['accountId'].' '.$r['accountName']
			];
			$data[$idx] = $item;
			$total += $item['kc_1a'];
			$idx++;
		}

		$this->setItem ('nonTaxCosts', $data);
		$this->setItem ('nonTaxCostsTotal', $total);
	}

	function createBalanceSheet ($thousands)
	{
		$reportParamsData = ($this->reportRecData['params'] != '') ? json_decode($this->reportRecData['params'], TRUE) : [];
		$bsType = Utils::cfgItem($reportParamsData, 'uv_rozsah_rozv', 'P');
		$bsDef = $this->reportVersion['balanceSheets'][$bsType];

		$report = new \e10doc\debs\libs\reports\ReportBalanceSheet($this->app());
		$report->fiscalPeriod = E10Utils::yearLastFiscalMonth($this->app(), $this->reportRecData['accPeriod']);
		$report->resultFormat = ($thousands) ? '1000' : '0';
		$report->subReportId = 'report';
		$report->version = $bsDef['version'];
		$report->variant = $bsDef['variant'];
		$report->init();
		$report->createContent_Report ();

		$dataItemId = 'balanceSheet';
		if ($thousands)
			$dataItemId .= 'K';
		else
		{
			foreach ($report->spd->subColumnsData as $key => $value)
				$report->spd->subColumnsData[$key] = intval(round(intval($report->spd->subColumnsData[$key]), 0));
		}
		$report->spd->subColumnsData['VARIANT'] = $bsType;
		$this->setItem ($dataItemId, $report->spd->subColumnsData);
	}

	function createStatement ($thousands)
	{
		$reportParamsData = ($this->reportRecData['params'] != '') ? json_decode($this->reportRecData['params'], TRUE) : [];
		$stType = Utils::cfgItem($reportParamsData, 'uv_rozsah_vzz', 'P');
		$stDef = $this->reportVersion['statements'][$stType];

		$report = new \e10doc\debs\libs\reports\ReportStatement($this->app());
		$report->fiscalPeriod = E10Utils::yearLastFiscalMonth($this->app(), $this->reportRecData['accPeriod']);
		$report->resultFormat = ($thousands) ? '1000' : '0';
		$report->subReportId = 'report';
		$report->version = $stDef['version'];
		$report->variant = $stDef['variant'];
		$report->init();
		$report->createContent_Report ();

		$dataItemId = 'statement';
		if ($thousands)
			$dataItemId .= 'K';
		else
		{
			foreach ($report->spd->subColumnsData as $key => $value)
				$report->spd->subColumnsData[$key] = intval(round(intval($report->spd->subColumnsData[$key]), 0));
		}
		$report->spd->subColumnsData['VARIANT'] = $stType;
		$this->setItem ($dataItemId, $report->spd->subColumnsData);
	}

	function createGeneralLedger ()
	{
		$report = new \e10doc\debs\libs\reports\GeneralLedger($this->app());
		$report->fiscalYear = $this->reportRecData['accPeriod'];
		$report->fiscalPeriod = E10Utils::yearLastFiscalMonth($this->app(), $this->reportRecData['accPeriod']);
		$report->init();
		$report->createContent ();

		$dataItemId = 'generalLedger';
		$data = ['totals' => []];
		foreach ($report->totals as $totalId => $total)
		{
			$item = $total;
			unset ($item['_options'], $item['title'], $item['accountId'], $item['accGroup']);
			foreach ($item as $key => $value)
				$item[$key] = intval(round($item[$key], 0));

			$data['totals'][$totalId] = $item;
		}
		$this->setItem ($dataItemId, $data);
	}
}
