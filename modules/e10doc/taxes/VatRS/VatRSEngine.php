<?php

namespace e10doc\taxes\VatRS;

use \e10\utils, \e10\Utility;
use \e10doc\core\libs\E10Utils;

/**
 * Class VatRSEngine
 */
class VatRSEngine extends \e10doc\taxes\TaxReportEngine
{
	var $rsTaxCodes;

	public function init ()
	{
		$this->taxReportId = 'eu-vat-rs';
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
		$recData['title'] = 'Souhrnné hlášení '.$vp ['id'];
	}

	public function documentAdd ($recData)
	{
		$vatRegCfg = $this->app()->cfgItem('e10doc.base.taxRegs.vat.'.$recData['vatReg'], NULL);
		if (!$vatRegCfg)
			return;
		if ($vatRegCfg['payerKind'] !== 0) // regular payer - not OSS
			return;

		$this->reportRecData = $this->searchReport($recData['dateTax'], $recData['vatReg']);

		$taxCodes = E10Utils::taxCodes($this->app(), $vatRegCfg['country']);

		$this->rsTaxCodes = [];
		foreach ($taxCodes as $key => $c)
		{
			if (isset ($c['intraCommunityCode']))
				$this->rsTaxCodes[] = $key;
		}

		if (!count($this->rsTaxCodes))
			return;

		$docRows = $this->db()->query (
				'SELECT * FROM [e10doc_core_taxes] WHERE [document] = %i', $recData['ndx'],
				' AND [taxCode] IN %in', $this->rsTaxCodes, ' ORDER by ndx'
				);

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

					'taxCode' => $r['taxCode'],
					'taxRate' => $r['taxRate'],
					'taxDir' => $taxCode['dir'],

					'document' => $recData['ndx'],
					'docNumber' => $recData['docNumber'],

					'dateTax' => $recData['dateTax'],
					'vatId' => $recData['personVATIN']
			];

			$this->db()->query('INSERT INTO [e10doc_taxes_reportsRowsVatRS] ', $newRow);
		}
	}

	public function documentRemove ($recData)
	{
		$this->db()->query ('DELETE FROM [e10doc_taxes_reportsRowsVatRS] WHERE [filing] = 0 AND [document] = %i', $recData['ndx']);
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

		$this->documentAdd($recData);
	}

	public function doRebuild ($recData)
	{
		$this->reportRecData = $recData;

		// -- remove old rows
		$this->db()->query ('DELETE FROM [e10doc_taxes_reportsRowsVatRS] WHERE [filing] = 0 AND [report] = %i', $recData['ndx']);

		// -- add new rows
		$q[] = 'SELECT * FROM [e10doc_core_heads]';
		array_push($q, ' WHERE 1');

		array_push($q, ' AND [docType] IN %in', $this->taxReportType['docTypes']);
		array_push($q, ' AND [vatReg] = %i', $this->reportRecData['taxReg']);
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
