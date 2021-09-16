<?php

namespace e10doc\taxes\VatOSS;
use \Shipard\Utils\Utils;
use \e10doc\core\libs\E10Utils;

/**
 * Class VatOSSEngine
 */
class VatOSSEngine extends \e10doc\taxes\TaxReportEngine
{
	public function init ()
	{
		$this->taxReportId = 'eu-vat-oss';
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
		$recData['title'] = 'DPH OSS '.$vp ['id'];
	}

	public function documentAdd ($recData)
	{
    $vatRegCfg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$recData['vatReg'], NULL);
    if (!$vatRegCfg)
		{
			error_log("__INVALID_VATREG_CFG__");
			return;
		}
    if ($vatRegCfg['payerKind'] !== 1) // OSS - not regular payer
		{
			error_log("__INVALID_PAYER_KIND__");
      return;
		}
    $this->reportRecData = $this->searchReport($recData['dateTax'], $recData['vatReg']);

		$taxCodes = E10Utils::docTaxCodes($this->app(), $recData);

		$docRows = $this->db()->query ('SELECT * FROM [e10doc_core_taxes] WHERE [document] = %i ORDER by ndx', $recData['ndx']);
		forEach ($docRows as $r)
		{
			if ($r['taxCode'] === '')
				continue;
			$taxCode = $taxCodes[$r['taxCode']];
			if (!isset($taxCode['dir']) || $taxCode['dir'] !== 1) // out / sale
			{
				error_log("__INVALID_TAXCODE_DIR_`{$r['taxCode']}`_");
				continue;
			}	
			$newRow = [
					'report' => $this->reportRecData['ndx'],

					'base' => $r['sumBaseTax'],
					'tax' => $r['sumTaxTax'],
					'total' => $r['sumTotalTax'],

          'countryConsumption' => $recData['taxCountry'],

					'taxCode' => $r['taxCode'],
					'taxRate' => $r['taxRate'],
          'taxPercents' => $r['taxPercents'],
					//'taxType' => $taxCode['type'],

					'document' => $recData['ndx'],
			];

			$this->db()->query('INSERT INTO [e10doc_taxes_reportsRowsVatOSS] ', $newRow);
		}
	}

	public function documentRemove ($recData)
	{
		$this->db()->query ('DELETE FROM [e10doc_taxes_reportsRowsVatOSS] WHERE [filing] = 0 AND [document] = %i', $recData['ndx']);
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
		$this->db()->query ('DELETE FROM [e10doc_taxes_reportsRowsVatOSS] WHERE [filing] = 0 AND [report] = %i', $recData['ndx']);

		// -- add new rows
		$q[] = 'SELECT * FROM [e10doc_core_heads]';
		array_push($q, ' WHERE 1');

		array_push($q, ' AND [docType] IN %in', $this->taxReportType['docTypes']);
		array_push($q, ' AND [docState] = %i', 4000);
		array_push($q, ' AND [vatReg] = %i', $this->reportRecData['taxReg']);
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
