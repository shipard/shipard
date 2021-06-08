<?php

namespace e10doc\debs\libs\reports;

use e10doc\core\libs\E10Utils;


/**
 * Class reportDocsSummary
 * @package Pkgs\Accounting\Debs
 */
class ReportDocsSummary extends \e10doc\core\libs\reports\GlobalReport
{
	public $fiscalPeriod = 0;
	public $fiscalYear = 0;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years', 'enableAll'], 'defaultValue' => E10Utils::todayFiscalMonth($this->app)]);
		parent::init();

		$this->fiscalPeriod = $this->reportParams ['fiscalPeriod']['value'];
		$this->fiscalYear = $this->reportParams ['fiscalPeriod']['values'][$this->fiscalPeriod]['fiscalYear'];

		$this->setInfo('title', 'Účetní rekapitulace');
		$this->setInfo('icon', 'report/docsSummary');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('saveFileName', 'Účetní rekapitulace '.str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']));

		//$this->paperOrientation = 'landscape';
	}

	function fiscalMonths ($endMonth, $periodType = FALSE, $count = 0)
	{
		$endMonthRec = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE ndx = %i", $endMonth)->fetch ();
		$months = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE fiscalType IN (0, 2) AND fiscalYear = %i AND [globalOrder] <= %i",
			$endMonthRec['fiscalYear'], $endMonthRec['globalOrder']);

		$monthList = array();
		forEach ($months as $m)
			$monthList[] = $m['ndx'];

		return $monthList;
	}

	function createContent_Data ()
	{
		$docTypes = $this->app->cfgItem ('e10.docs.types');
		$docPaymentMethods = $this->app->cfgItem ('e10.docs.paymentMethods');

		// -- account names
		$qac = 'SELECT id, shortName FROM e10doc_debs_accounts WHERE docStateMain < 3';
		$accounts = $this->app->db()->query($qac);
		$accNames = $accounts->fetchPairs ('id', 'shortName');

		// -- month summary
		$q[] = 'SELECT j.docType as docType, h.paymentMethod as paymentMethod, j.accountId as accountId, SUM(j.moneyDr) as sumMDr, SUM(j.moneyCr) as sumMCr';
		array_push($q, ' FROM e10doc_debs_journal as j LEFT JOIN e10doc_core_heads as h ON (j.document = h.ndx)');
		array_push ($q, ' WHERE 1');
		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value'], 'j.');
		array_push ($q, ' GROUP BY j.docType, h.paymentMethod, j.accountId');
		$accSumM = $this->app->db()->query($q);
		$all = array ();
		$prevDocType = '';
		$prevPaymentMethod = 0;
		$subTotalDr = 0.0;
		$subTotalCr = 0.0;
		$totalDr = 0.0;
		$totalCr = 0.0;
		forEach ($accSumM as $acc)
		{
			$docType = $acc['docType'];
			$paymentMethod = $acc['paymentMethod'];
			$accountId = $acc['accountId'];
			$acc['title'] = $accNames[$accountId];
			if ($prevDocType != $docType || $prevPaymentMethod != $paymentMethod)
			{
				if ($prevDocType != "")
					$all[] = array ('accountId' => "Σ", 'sumMDr' => $subTotalDr, 'sumMCr' => $subTotalCr, '_options' => array ('class' => 'subtotal'));

				$subTotalDr = 0.0;
				$subTotalCr = 0.0;
				$all[] = array ('accountId' => array ('text'=> $docTypes[$docType]['pluralName'].' ['.$docPaymentMethods[$paymentMethod]['title'].']',
					'icon' => $docTypes[$docType]['icon']),
					'_options' => array ('class' => 'subheader separator', 'colSpan' => array ('accountId' => 4)));
				$prevDocType = $docType;
				$prevPaymentMethod = $paymentMethod;
			}
			$subTotalDr += $acc['sumMDr'];
			$subTotalCr += $acc['sumMCr'];
			$totalDr += $acc['sumMDr'];
			$totalCr += $acc['sumMCr'];
			$all[] = $acc;
		}
		$all[] = array ('accountId' => "Σ", 'sumMDr' => $subTotalDr, 'sumMCr' => $subTotalCr, '_options' => array ('class' => 'subtotal'));
		$all[] = array ('accountId' => "Σ", 'sumMDr' => $totalDr, 'sumMCr' => $totalCr, '_options' => array ('class' => 'sumtotal', 'beforeSeparator' => 'separator'));

		return $all;
	}

	function createContent ()
	{
		$data = $this->createContent_Data ();
		$h = array ('accountId' => 'Účet', 'sumMDr' => ' Obrat MD', 'sumMCr' => ' Obrat DAL', 'title' => 'Text');
		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE, 'params' => ['disableZeros' => 1]));
	}
}
