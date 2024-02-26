<?php

namespace e10doc\taxes\VatCS;

use \e10\utils, \e10\Utility;
use \e10doc\core\libs\E10Utils;


/**
 * Class TaxReportEngineVatCS
 * @package e10doc\taxes
 */
class VatCSEngine extends \e10doc\taxes\TaxReportEngine
{
	var $rowKinds;

	public function init ()
	{
		$this->taxReportId = 'cz-vat-cs';
		parent::init();

		$this->rowKinds = [
			// výstup
			'A1' => ['name' => 'pdp'], // reverseChargeCode
			'A2' => ['name' => 'intrakomunitární'],
			'A3' => ['name' => 'zlato'],
			'A4' => ['name' => 'tuzemsko > 10000'],
			'A5' => ['name' => 'tuzemsko <= 10000'],
			// -- vstup
			'B1' => ['name' => 'pdp'], // reverseChargeCode
			'B2' => ['name' => 'tuzemsko > 10000'],
			'B3' => ['name' => 'tuzemsko <= 10000'],
		];
	}

	public function checkNewReport ($forDate, &$recData)
	{
		$vatReg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$recData['taxReg'], NULL);
		if (!$vatReg)
		{
			error_log ("---VAT-REG-NOT-FOUND!!!!");
			return;
		}

		if ($vatReg['periodTypeVatCS'] == 1)
		{ // monthly
			$year = intval($forDate->format('Y'));
			$month = intval($forDate->format('m'));

			$beginDateStr = sprintf('%04d-%02d-01', $year, $month);
			$beginDate = new \DateTime ($beginDateStr);
			$endDateStr = $beginDate->format('Y-m-t');
			$endDate = new \DateTime ($endDateStr);
			$periodName = $year.'/'.$month;
		}
		elseif ($vatReg['periodTypeVatCS'] == 2)
		{ // quarterly
			$year = intval($forDate->format('Y'));
			$month = intval($forDate->format('m'));
			$q = intval(($month - 1) / 3) + 1; // 1..4
			$monthBegin = ($q - 1) * 3 + 1;
			$monthEnd = $monthBegin + 2;

			$beginDateStr = sprintf('%04d-%02d-01', $year, $monthBegin);
			$beginDate = new \DateTime ($beginDateStr);

			$endDate1 = new \DateTime(sprintf('%04d-%02d-01', $year, $monthEnd));//$beginDate->format('Y-m-t');
			$endDateStr = $endDate1->format('Y-m-t');
			$endDate = new \DateTime ($endDateStr);

			$periodName = $year.'/'.$q.'Q';
		}

		$recData['datePeriodBegin'] = $beginDate;
		$recData['datePeriodEnd'] = $endDate;
		$recData['title'] = 'Kontrolní hlášení DPH '.$periodName;

		// -- vat period
		$qvp = 'SELECT [ndx] FROM [e10doc_base_taxperiods] WHERE [start] <= %d AND [end] >= %d';
		$vp = $this->db()->query ($qvp, $forDate, $forDate)->fetch ();
		if ($vp)
			$recData ['taxPeriod'] = $vp ['ndx'];
	}

	function rowKind ($docRecData, $rowRecData, $taxCode)
	{
		$rowKind = '';

		if (!isset($taxCode['dir']) || $docRecData['vatCS'] == 3)
			return $rowKind;

		if ($taxCode['dir'] === 0)
		{ // vstup - B
			if ($taxCode['type'] !== 0)
				return ''; // intrakomunitární/zahraničí
			if (isset($taxCode['reverseCharge']) && $taxCode['reverseCharge'])
				$rowKind = 'B1.'.$taxCode['reverseChargeCode'];
			else
			if ((abs($docRecData['costTotalHc']) > 10000.00 && $docRecData['vatCS'] != 2) || $docRecData['vatCS'] == 1)
				$rowKind = 'B2';
			else
				$rowKind = 'B3';
		}
		elseif ($taxCode['dir'] === 1)
		{ // výstup - A
			if ($taxCode['type'] === 0)
			{ // tuzemsko
				if (isset($taxCode['reverseCharge']) && $taxCode['reverseCharge'])
					$rowKind = 'A1.'.$taxCode['reverseChargeCode'];
				else
				{
					if (in_array($taxCode['rowTaxReturn'], [1, 2]))
					{
						if ((abs($docRecData['sumTotalHc']) > 10000.00 &&
										$docRecData['personVATIN'] !== '' && substr ($docRecData['personVATIN'], 0, 2) === 'CZ' &&
										$docRecData['vatCS'] != 2) || $docRecData['vatCS'] == 1)
							$rowKind = 'A4';
						else
							$rowKind = 'A5';
					}
				}
			}
			else
			if ($taxCode['type'] === 1)
			{ // intrakomunitární
				if (in_array($taxCode['rowTaxReturn'], [3, 4, 9, 5, 6, 12, 13]))
					$rowKind = 'A2';
			}
		}

		return $rowKind;
	}

	public function documentAdd ($recData)
	{
		if ($recData['taxCalc'] == 0)
			return;

		$vatRegCfg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$recData['vatReg'], NULL);
		if (!$vatRegCfg)
		{
			return;
		}
		if ($vatRegCfg['taxCountry'] !== 'cz')
		{
			return;
		}
		if ($vatRegCfg['payerKind'] !== 0) // regular payer, not OSS
		{
			return;
		}

		$this->reportRecData = $this->searchReport($recData['dateTax'], $recData['vatReg']);
		$taxCodes = E10Utils::docTaxCodes($this->app(), $recData);

		$docRows = $this->db()->query ('SELECT * FROM [e10doc_core_taxes] WHERE [document] = %i ORDER by ndx', $recData['ndx']);
		$newRows = [];
		forEach ($docRows as $r)
		{
			$taxCode = $taxCodes[$r['taxCode']];
			$rowKind = $this->rowKind($recData, $r, $taxCode);

			if ($rowKind === '')
				continue;

			if (!isset($newRows[$rowKind]))
			{
				$newRows[$rowKind] = [
						'report' => $this->reportRecData['ndx'],
						'rowKind' => $rowKind,

						'document' => $recData['ndx'],
						'docNumber' => $recData['docNumber'],
						'docId' => ($recData['docId'] === '') ? $recData['symbol1'] : $recData['docId'],

						'dateTax' => $recData['dateTax'],
						'dateTaxDuty' => utils::dateIsBlank($recData['dateTaxDuty']) ? $recData['dateTax'] : $recData['dateTaxDuty'],
						'vatId' => $recData['personVATIN'],

						'base1' => 0, 'tax1' => 0, 'total1' => 0,
						'base2' => 0, 'tax2' => 0, 'total2' => 0,
						'base3' => 0, 'tax3' => 0, 'total3' => 0,
				];

				if (isset($taxCode['reverseCharge']) && $taxCode['reverseCharge'])
					$newRows[$rowKind]['reverseChargeCode'] = $taxCode['reverseChargeCode'];
			}

			switch ($taxCode['rate'])
			{
				case 0: // základní
							$newRows[$rowKind]['base1'] += $r['sumBaseHc'];
							$newRows[$rowKind]['tax1'] += $r['sumTaxHc'];
							$newRows[$rowKind]['total1'] += $r['sumTotalHc'];
							break;
					case 1: // snížená
							$newRows[$rowKind]['base2'] += $r['sumBaseHc'];
							$newRows[$rowKind]['tax2'] += $r['sumTaxHc'];
							$newRows[$rowKind]['total2'] += $r['sumTotalHc'];
							break;
					case 4: // první snížená
							$newRows[$rowKind]['base2'] += $r['sumBaseHc'];
							$newRows[$rowKind]['tax2'] += $r['sumTaxHc'];
							$newRows[$rowKind]['total2'] += $r['sumTotalHc'];
							break;
				case 5: // druhá snížená
							$newRows[$rowKind]['base3'] += $r['sumBaseHc'];
							$newRows[$rowKind]['tax3'] += $r['sumTaxHc'];
							$newRows[$rowKind]['total3'] += $r['sumTotalHc'];
							break;
			}
		}
		foreach ($newRows as $newRow)
		{
			$this->db()->query('INSERT INTO [e10doc_taxes_reportsRowsVatCS] ', $newRow);
		}
	}

	public function documentRemove ($recData)
	{
		$this->db()->query ('DELETE FROM [e10doc_taxes_reportsRowsVatCS] WHERE [filing] = 0 AND [document] = %i', $recData['ndx']);
	}

	public function doDocument($recData)
	{
		$this->documentRemove($recData);

		if (!$this->validForDate($recData['dateTax']))
			return;
		if ($recData['docState'] === 4100)
			return;

		$this->documentAdd($recData);
	}

	public function doRebuild ($recData)
	{
		$this->reportRecData = $recData;

		// -- remove old rows
		$this->db()->query ('DELETE FROM [e10doc_taxes_reportsRowsVatCS] WHERE [filing] = 0 AND [report] = %i', $recData['ndx']);

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
