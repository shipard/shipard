<?php

namespace e10doc\taxes\VatReturn;

use \e10\utils, \e10\Utility;


/**
 * Class VatReturnEngine
 * @package e10doc\taxes
 */
class VatReturnEngine extends \e10doc\taxes\TaxReportEngine
{
	public function init ()
	{
		$this->taxReportId = 'eu-vat-tr';
		parent::init();
	}

	public function checkNewReport ($forDate, &$recData)
	{
		$qvp = 'SELECT * FROM [e10doc_base_taxperiods] WHERE [start] <= %d AND [end] >= %d AND [vatReg] = %i';
		$vp = $this->db()->query ($qvp, $forDate, $forDate, $recData['taxReg'])->fetch ();
		if (!$vp)
			return FALSE;

		$recData['taxPeriod'] = $vp ['ndx'];
		$recData['datePeriodBegin'] = $vp ['start'];
		$recData['datePeriodEnd'] = $vp ['end'];
		$recData['title'] = 'Přiznání DPH '.$vp ['id'];
	}

	public function documentAdd ($recData)
	{
		$taxCodes = $this->app->cfgItem ('e10.base.taxCodes');

		$docRows = $this->db()->query ('SELECT * FROM [e10doc_core_taxes] WHERE [document] = %i ORDER by ndx', $recData['ndx']);
		forEach ($docRows as $r)
		{
			if (!$r['taxCode'])
				continue;
			$taxCode = $taxCodes[$r['taxCode']];
			if (!isset($taxCode['dir']))
				continue;

			$newRow = [
					'report' => $this->reportRecData['ndx'],

					'base' => $r['sumBaseHc'],
					'tax' => $r['sumTaxHc'],
					'total' => $r['sumTotalHc'],

					'taxCode' => $r['taxCode'],
					'taxRate' => $r['taxRate'],
					'taxDir' => $taxCode['dir'],
					//'taxType' => $taxCode['type'],

					'document' => $recData['ndx'],
					'docNumber' => $recData['docNumber'],
					'docId' => $recData['docId'],

					'dateTax' => $recData['dateTax'],
					'dateTaxDuty' => $recData['dateTaxDuty'],
					'vatId' => $recData['personVATIN'],

					'quantity' => $r['quantity'],
					'weight' => $r['weight'],
			];

			$this->db()->query('INSERT INTO [e10doc_taxes_reportsRowsVatReturn] ', $newRow);
		}
	}

	public function documentRemove ($recData)
	{
		$this->db()->query ('DELETE FROM [e10doc_taxes_reportsRowsVatReturn] WHERE [filing] = 0 AND [document] = %i', $recData['ndx']);
	}

	public function doDocument($recData)
	{
		$this->documentRemove($recData);

		if (!$this->validForDate($recData['dateTax']))
			return;
		if ($recData['taxCalc'] == 0)
			return;
		if ($recData['docState'] === 4100)
			return;

		$this->reportRecData = $this->searchReport($recData['dateTax'], $recData['vatReg']);
		$this->documentAdd($recData);
	}

	public function doRebuild ($recData)
	{
		$this->reportRecData = $recData;

		// -- remove old rows
		$this->db()->query ('DELETE FROM [e10doc_taxes_reportsRowsVatReturn] WHERE [filing] = 0 AND [report] = %i', $recData['ndx']);

		// -- add new rows
		$q[] = 'SELECT * FROM [e10doc_core_heads]';
		array_push($q, ' WHERE 1');

		array_push($q, ' AND [docType] IN %in', $this->taxReportType['docTypes']);
		array_push($q, ' AND [docState] = %i', 4000);
		array_push($q, ' AND [dateTax] >= %d', $recData['datePeriodBegin']);
		array_push($q, ' AND [dateTax] <= %d', $recData['datePeriodEnd']);
		array_push($q, ' ORDER BY [dateAccounting], [docNumber]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->documentAdd($r);
		}
	}

}
