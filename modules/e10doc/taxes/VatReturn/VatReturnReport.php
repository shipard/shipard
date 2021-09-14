<?php

namespace e10doc\taxes\VatReturn;

use e10\utils, e10\uiutils, e10\json, e10doc\core\libs\E10Utils;


/**
 * Class VatReturnReport
 * @package e10doc\taxes\VatReturn
 */
class VatReturnReport extends \e10doc\taxes\TaxReportReport
{
	var $taxCodes;
	var $oldVatReturn = NULL;
	var $disableOldVatReturn = FALSE;

	function init()
	{
		$this->taxReportTypeId = 'eu-vat-tr';
		$this->previewReportTemplate = 'reports.default.e10doc.taxes.tax-eu-vat-tr/cz';
		$this->filingTypeEnum = ['B' => 'Řádné', 'O' => 'Opravné', 'D' => 'Dodatečné'];
		
		parent::init();

		$this->taxCodes = E10Utils::taxCodes($this->app(), $this->taxRegCfg['country']);
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icontxt' => '∑', 'title' => 'Sumárně'];
		$d[] = ['id' => 'out', 'icon' => 'detailReportOutputCsReport', 'title' => 'Výstup'];
		$d[] = ['id' => 'in', 'icon' => 'detailReportInputCsReport', 'title' => 'Vstup'];
		$d[] = ['id' => 'revCharge', 'icon' => 'detailReportPDP', 'title' => 'PDP'];
		$d[] = ['id' => 'preview', 'icon' => 'detailReportTranscript', 'title' => 'Opis'];
		$d[] = ['id' => 'errors', 'icon' => 'detailReportProblems', 'title' => 'Problémy'];
		$d[] = ['id' => 'filings', 'icon' => 'detailReportDifferences', 'title' => 'Rozdíly'];

		return $d;
	}

	function calcTaxReturn ()
	{
		$this->data['rows'] = [];
		foreach ($this->data['SUM'] as $r)
		{
			$taxCode = $this->taxCodes[$r['taxCode']];
			if (!isset($taxCode['rowTaxReturn']) || !$taxCode['rowTaxReturn'])
				continue;

			$rid = 'row'.$taxCode['rowTaxReturn'];

			if (!isset($this->data['rows'][$rid]))
				$this->data['rows'][$rid] = ['base' => 0, 'tax' => 0, 'total' => 0];

			$this->data['rows'][$rid]['base'] += $r['sumBase'];
			$this->data['rows'][$rid]['tax'] += $r['sumTax'];
			$this->data['rows'][$rid]['total'] += $r['sumTotal'];
		}

		foreach ($this->data['rows'] as $rid => $row)
		{
			$this->data['rowsRounded'][$rid] = [
					'base' => round($this->data['rows'][$rid]['base']),
					'tax' => round($this->data['rows'][$rid]['tax']),
					'total' => round($this->data['rows'][$rid]['total'])
			];
		}

		$this->calcTaxReturnRow(46, [40, 41, 42, 43, 44, 45]);
		$this->calcTaxReturnRow(62, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, -61]);
		$this->calcTaxReturnRow(63, [46, 52, 53, 60]);

		$totalTaxVatReturn = $this->data['rowsRounded']['row62']['tax'] - $this->data['rowsRounded']['row63']['tax'];

		$this->data['rows']['row66']['tax'] = 0;
		$this->data['rowsRounded']['row66']['tax'] = 0;

		if ($totalTaxVatReturn < 0)
		{
			$this->data['rows']['row64']['tax'] = 0;
			$this->data['rows']['row65']['tax'] = -$totalTaxVatReturn;
			$this->data['rowsRounded']['row64']['tax'] = 0;
			$this->data['rowsRounded']['row65']['tax'] = -$totalTaxVatReturn;
			if ($this->oldVatReturn)
				$this->data['rowsRounded']['row66']['tax'] =
						$totalTaxVatReturn - ($this->oldVatReturn->data['rowsRounded']['row62']['tax'] - $this->oldVatReturn->data['rowsRounded']['row63']['tax']);
		}
		else
		{
			$this->data['rows']['row64']['tax'] = $totalTaxVatReturn;
			$this->data['rows']['row65']['tax'] = 0;
			$this->data['rowsRounded']['row64']['tax'] = $totalTaxVatReturn;
			$this->data['rowsRounded']['row65']['tax'] = 0;
			if ($this->oldVatReturn)
				$this->data['rowsRounded']['row66']['tax'] =
						$totalTaxVatReturn  - ($this->oldVatReturn->data['rowsRounded']['row62']['tax'] - $this->oldVatReturn->data['rowsRounded']['row63']['tax']);
		}

		$this->data['rows']['row66']['tax'] = $this->data['rowsRounded']['row66']['tax'];

		if ($this->oldVatReturn)
		{
			foreach ($this->data['rows'] as $rid => $row)
			{
				$this->data['rows'][$rid]['base'] -= $this->oldVatReturn->data['rows'][$rid]['base'];
				$this->data['rows'][$rid]['tax'] -= $this->oldVatReturn->data['rows'][$rid]['tax'];
				$this->data['rows'][$rid]['total'] -= $this->oldVatReturn->data['rows'][$rid]['total'];

				$this->data['rowsRounded'][$rid]['base'] -= $this->oldVatReturn->data['rowsRounded'][$rid]['base'];
				$this->data['rowsRounded'][$rid]['tax'] -= $this->oldVatReturn->data['rowsRounded'][$rid]['tax'];
				$this->data['rowsRounded'][$rid]['total'] -= $this->oldVatReturn->data['rowsRounded'][$rid]['total'];
			}

			$this->data['rows']['row64']['tax'] = 0;
			$this->data['rows']['row65']['tax'] = 0;
			$this->data['rowsRounded']['row64']['tax'] = 0;
			$this->data['rowsRounded']['row65']['tax'] = 0;
		}

		foreach ($this->data['rowsRounded'] as $rid => $row)
		{
			$this->data['rowsPrint'][$rid] = ['base' => utils::nf($row['base']), 'tax' => utils::nf($row['tax']),'total' => utils::nf($row['total']) ];
		}
	}

	function calcTaxReturnRow ($dstRow, $srcRows)
	{
		$dstRowId = 'row'.$dstRow;
		if (!isset($this->data['rows'][$dstRowId]))
			$this->data['rows'][$dstRowId] = ['base' => 0, 'tax' => 0, 'total' => 0];
		if (!isset($this->data['rowsRounded'][$dstRowId]))
			$this->data['rowsRounded'][$dstRowId] = ['base' => 0, 'tax' => 0, 'total' => 0];

		foreach ($srcRows as $sr)
		{
			$srcRowId = 'row'.abs($sr);

			if (!isset($this->data['rows'][$srcRowId]))
				continue;

			if ($sr < 0)
			{
				$this->data['rows'][$dstRowId]['base'] -= $this->data['rows'][$srcRowId]['base'];
				$this->data['rows'][$dstRowId]['tax'] -= $this->data['rows'][$srcRowId]['tax'];
				$this->data['rows'][$dstRowId]['total'] -= $this->data['rows'][$srcRowId]['total'];

				$this->data['rowsRounded'][$dstRowId]['base'] -= $this->data['rowsRounded'][$srcRowId]['base'];
				$this->data['rowsRounded'][$dstRowId]['tax'] -= $this->data['rowsRounded'][$srcRowId]['tax'];
				$this->data['rowsRounded'][$dstRowId]['total'] -= $this->data['rowsRounded'][$srcRowId]['total'];
			}
			else
			{
				$this->data['rows'][$dstRowId]['base'] += $this->data['rows'][$srcRowId]['base'];
				$this->data['rows'][$dstRowId]['tax'] += $this->data['rows'][$srcRowId]['tax'];
				$this->data['rows'][$dstRowId]['total'] += $this->data['rows'][$srcRowId]['total'];

				$this->data['rowsRounded'][$dstRowId]['base'] += $this->data['rowsRounded'][$srcRowId]['base'];
				$this->data['rowsRounded'][$dstRowId]['tax'] += $this->data['rowsRounded'][$srcRowId]['tax'];
				$this->data['rowsRounded'][$dstRowId]['total'] += $this->data['rowsRounded'][$srcRowId]['total'];
			}
		}
	}

	function createContent ()
	{
		if (!$this->taxReportDef)
			return;

		$this->loadData();

		if ($this->cntErrors && $this->subReportId != 'errors' && $this->format !== 'pdf')
		{
			$msg = ['text' => 'Přiznání DPH patrně obsahuje chyby. Zkontrolujte prosím pravou záložku Problémy.', 'class' => 'padd5 e10-warning2 h2 block center'];
			$this->addContent(['type' => 'line', 'line' => $msg]);
		}

		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Sum (); break;
			case 'out': $this->createContent_Dir (1); break;
			case 'in': $this->createContent_Dir (0); break;
			case 'revCharge': $this->createContent_ReverseCharge(); break;
			case 'preview': $this->createContent_Preview (); break;
			case 'errors': $this->createContent_Errors (); break;
			case 'filings': $this->createContent_Filings(); break;
			case 'ALL': $this->createContent_All (); break;
		}
		$this->setInfo('saveFileName', $this->taxReportRecData['title']);

		$this->createContentXml();
	}

	public function createContent_All ()
	{
		$this->createContent_Sum();
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='pageBreakAfter'></div>"]);

		$this->createContent_Dir (1);
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='pageBreakAfter'></div>"]);

		$this->createContent_Dir (0);
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='pageBreakAfter'></div>"]);

		$this->createContent_ReverseCharge();
	}

	function createContent_Sum ()
	{
		$table = [];

		$sum = [];
		$sum['total'] = [
				'rate' => ['text' => 'BILANCE'],
				'base' => 0.0, 'tax' => 0.0, 'total' => 0.0,
				'_options' => ['class' => 'sumtotal']
		];

		foreach (['1', '0'] as $tid)
		{
			$dirName = $this->app->cfgItem ('e10.base.taxDir.'.$tid);

			$title = [
					'rate' => $dirName, 'row' => ' Řádek přiznání', 'base' => ' Základ', 'tax' => ' Daň', 'total' => ' Celkem',
					'_options' => ['class' => 'subtotal']
			];
			$table[] = $title;

			$sum[$tid] = [
					'rate' => 'Celkem',
					'base' => 0.0, 'tax' => 0.0, 'total' => 0.0,
					'_options' => ['class' => 'subtotal', 'afterSeparator' => 'separator']
			];

			foreach ($this->data['SUM'] as $r)
			{
				if ($r['taxDir'] != $tid)
					continue;

				$taxCode = $this->taxCodes[$r['taxCode']];
				if (!isset($taxCode['rowTaxReturn']) || !$taxCode['rowTaxReturn'])
					continue;

				$item = [
						'rate' => $taxCode['fullName'], 'row' => $taxCode['rowTaxReturn'],
						'base' => $r['sumBase'], 'tax' => $r['sumTax'], 'total' => $r['sumTotal']
				];
				$table[] = $item;

				$sum[$tid]['base'] += $r['sumBase'];
				$sum[$tid]['tax'] += $r['sumTax'];
				$sum[$tid]['total'] += $r['sumTotal'];

				if ($tid == '1')
				{
					$sum['total']['base'] += $r['sumBase'];
					$sum['total']['tax'] += $r['sumTax'];
					$sum['total']['total'] += $r['sumTotal'];
				}
				else
				{
					$sum['total']['base'] -= $r['sumBase'];
					$sum['total']['tax'] -= $r['sumTax'];
					$sum['total']['total'] -= $r['sumTotal'];
				}
			}
			$table[] = $sum[$tid];
		}

		if ($sum['total']['tax'] < 0.0)
			$sum['total']['rate']['suffix'] = 'Vratka DPH';
		else
			$sum['total']['rate']['suffix'] = 'Odvod DPH';

		$table[] = $sum['total'];

		$title = [['text' => 'Sumární přehled', 'class' => 'h2']];

		$h = ['#' => '#', 'rate' => 'Sazba', 'row' => ' Řádek přiznání', 'base' => ' Základ', 'tax' => ' Daň', 'total' => ' Celkem'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'params' => ['hideHeader' => 1]]);
	}

	function createContent_Dir ($dir)
	{
		$did = 'D' . $dir;

		$sum = [];
		$sum['total'] = [
				'docNumber' => ['text' => 'CELKEM'],
				'base' => 0.0, 'tax' => 0.0, 'total' => 0.0,
				'_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['docNumber' => 3]]
		];


		$table = [];
		$lastTaxCode = -1;
		foreach ($this->data[$did] as $r)
		{
			$tc = $r['taxCode'];
			$taxCode = $this->taxCodes[$tc];
			if (!isset($taxCode['rowTaxReturn']) || !$taxCode['rowTaxReturn'])
				continue;

			if ($lastTaxCode != $tc)
			{
				// -- sum past tax code
				if ($lastTaxCode != -1)
					$table[] = $sum[$lastTaxCode];

				// -- title
				$title = [
						'docNumber' => $taxCode['fullName'],
						'_options' => ['class' => 'subheader', 'beforeSeparator' => 'separator', 'colSpan' => ['docNumber' => 6]]
				];
				$table[] = $title;

				// -- sum init
				$sum[$tc] = [
						'docNumber' => $taxCode['fullName'],
						'base' => 0.0, 'tax' => 0.0, 'total' => 0.0,
						'_options' => ['class' => 'subtotal', 'xxafterSeparator' => 'separator', 'colSpan' => ['docNumber' => 3]]
				];
			}


			$item = ['dateTax' => $r['dateTax'], 'base' => $r['base'], 'tax' => $r['tax'], 'total' => $r['total']];
			$item['docNumber'] = $this->docNumber($r, $item);
			$item['vatId'] = $this->vatId($r);

			$table[] = $item;

			$sum[$tc]['base'] += $r['base'];
			$sum[$tc]['tax'] += $r['tax'];
			$sum[$tc]['total'] += $r['total'];

			$sum['total']['base'] += $r['base'];
			$sum['total']['tax'] += $r['tax'];
			$sum['total']['total'] += $r['total'];

			$lastTaxCode = $r['taxCode'];
		}

		if ($lastTaxCode != -1)
			$table[] = $sum[$lastTaxCode];

		$table[] = $sum['total'];

		$dirName = $this->app->cfgItem ('e10.base.taxDir.'.$dir);
		$title = [['text' => $dirName, 'class' => 'h2']];
		$h = ['#' => '#', 'docNumber' => 'Doklad', 'dateTax' => 'DUZP', 'vatId' => 'DIČ', 'base' => ' Základ', 'tax' => ' Daň', 'total' => ' Celkem'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE]);
	}

	function createContent_ReverseCharge ()
	{
		$table = [];

		$sum = [];

		foreach (['1', '0'] as $tid)
		{
			$dirName = $this->app->cfgItem ('e10.base.taxDir.'.$tid);

			$title = [
					'docNumber' => $dirName,
					'_options' => ['class' => 'subheader', 'beforeSeparator' => 'separator', 'colSpan' => ['docNumber' => 6]]
			];
			$table[] = $title;

			$sum[$tid] = [
					'docNumber' => 'Celkem '.$dirName,
					'base' => 0.0, 'tax' => 0.0, 'total' => 0.0,
					'_options' => ['class' => 'subtotal', 'xafterSeparator' => 'separator']
			];

			foreach ($this->data['rc'.$tid] as $r)
			{
				if ($r['taxDir'] != $tid)
					continue;

				$taxCode = $this->taxCodes[$r['taxCode']];

				$item = ['base' => $r['base'], 'dateTax' => $r['dateTax'], 'code' => $taxCode['reverseChargeCode']];
				$item['docNumber'] = $this->docNumber($r, $item);
				$item['vatId'] = $this->vatId($r);
				if (isset($taxCode['reverseChargeAmount']) && $taxCode['reverseChargeAmount'] === 'w')
					$item['amount'] = utils::nf ($r['weight']).' kg';

				$table[] = $item;

				$sum[$tid]['base'] += $r['base'];
			}
			$table[] = $sum[$tid];
		}

		$title = [['text' => 'Přenesení daňové povinnosti', 'class' => 'h2']];
		$h = ['#' => '#', 'docNumber' => 'Doklad', 'vatId' => 'DIČ', 'code' => ' Kód', 'dateTax' => 'DUZP', 'base' => ' Základ', 'amount' => ' Rozsah plnění'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE]);
	}

	public function createContent_Errors ()
	{
		$this->createContent_Errors_InvalidDocs();
//		$this->createContent_Errors_InvalidPersons();
		//	$this->createContent_Errors_BadVatIds();
	}

	public function createContent_Preview ()
	{
		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div style='text-align: center;'>"]);

		$this->data['currentPageNumber'] = 1;
		$this->data['cntPagesTotal'] = 2;

		$c = $this->renderFromTemplate ('reports.default.e10doc.taxes.tax-eu-vat-tr/cz', 'header');
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);

		$c = $this->renderFromTemplate ('reports.default.e10doc.taxes.tax-eu-vat-tr/cz', 'content');
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);

		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</div>"]);
	}

	public function createContent_Filings ()
	{
		$prevFilingNdx = -1;
		$prevFilingName = '';
		foreach ($this->enumFilings as $filingNdx => $filingName)
		{
			if ($prevFilingNdx != -1)
			{
				$this->addContent(['type' => 'line', 'line' => ['text' => $prevFilingName.' -> '.$filingName, 'class' => 'h1 block e10-row-this padd5']]);
				$cntChanges = $this->createContent_FilingsDiff ($prevFilingNdx, $filingNdx);
				if (!$cntChanges)
				{
					$this->addContent(['type' => 'line', 'line' => ['text' => 'Nebyly nalezeny žádné rozdíly', 'class' => 'block padd5']]);
				}
			}
			$prevFilingNdx = $filingNdx;
			$prevFilingName = $filingName;
		}
	}

	public function createContent_FilingsDiff ($filing1Ndx, $filing2Ndx)
	{
		$cntChanges = 0;

		// -- changes
		$changes = [];
		$q = [];
		array_push ($q, 'SELECT t1.* FROM [e10doc_taxes_reportsRowsVatReturn] AS t1');
		array_push ($q, 'WHERE t1.filing = %i', $filing1Ndx, ' AND [report] = %i', $this->taxReportNdx);

		array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10doc_taxes_reportsRowsVatReturn AS t2 WHERE t1.document = t2.document AND t1.taxCode = t2.taxCode',
				' AND t2.filing = %i', $filing2Ndx, ' AND t2.[report] = %i', $this->taxReportNdx,
				' AND (',
						't1.base != t2.base ', ' OR t1.tax != t2.tax ', ' OR t1.total != t2.total ',
						')',
				')');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$taxCode = $this->taxCodes[$r['taxCode']];
			$item = ['base' => $r['base'], 'tax' => $r['tax']];
			$item['docNumber'] = $this->docNumber($r, $item);
			$changes[] = $item;
		}

		if (count($changes))
		{
			$title = 'Změny';
			$h = ['#' => '#', 'docNumber' => 'Doklad', 'vatId' => 'DIČ', 'taxCode' => 'Sazba DPH', 'dateTax' => 'DUZP', 'base' => ' Základ', 'tax' => ' Daň'];
			$this->addContent(['type' => 'table', 'header' => $h, 'table' => $changes, 'title' => $title]);
			$cntChanges += count($changes);
		}

		// -- new rows
		$changes = [];
		$q = [];
		array_push ($q, 'SELECT t1.* FROM [e10doc_taxes_reportsRowsVatReturn] AS t1');
		array_push ($q, 'WHERE t1.filing = %i', $filing1Ndx, ' AND [report] = %i', $this->taxReportNdx);

		array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10doc_taxes_reportsRowsVatReturn AS t2 WHERE t1.document = t2.document',
				'AND t2.filing = %i', $filing2Ndx, ' AND t2.[report] = %i', $this->taxReportNdx,
				')');

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$taxCode = $this->taxCodes[$r['taxCode']];
			$item = ['base' => $r['base'], 'tax' => $r['tax'], 'taxCode' => $taxCode['fullName'], 'dateTax' => $r['dateTax']];
			$item['docNumber'] = $this->docNumber($r, $item);
			$item['vatId'] = $r['vatId'];
			$changes[] = $item;
		}

		if (count($changes))
		{
			$title = 'Nové doklady';
			$h = ['#' => '#', 'docNumber' => 'Doklad', 'dateTax' => 'DUZP', 'vatId' => 'DIČ', 'taxCode' => 'Sazba DPH', 'base' => ' Základ', 'tax' => ' Daň'];
			$this->addContent(['type' => 'table', 'header' => $h, 'table' => $changes, 'title' => $title]);
			$cntChanges += count($changes);
		}

		// -- missing rows
		$changes = [];
		$q = [];
		array_push ($q, 'SELECT t1.* FROM [e10doc_taxes_reportsRowsVatReturn] AS t1');
		array_push ($q, 'WHERE t1.filing = %i', $filing2Ndx, ' AND [report] = %i', $this->taxReportNdx);

		array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10doc_taxes_reportsRowsVatReturn AS t2 WHERE t1.document = t2.document',
				'AND t2.filing = %i', $filing1Ndx, ' AND t2.[report] = %i', $this->taxReportNdx,
				')');

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$taxCode = $this->taxCodes[$r['taxCode']];
			$item = ['base' => $r['base'], 'tax' => $r['tax'], 'taxCode' => $taxCode['fullName'], 'dateTax' => $r['dateTax']];
			$item['docNumber'] = $this->docNumber($r, $item);
			$item['vatId'] = $r['vatId'];
			$changes[] = $item;
		}

		if (count($changes))
		{
			$title = 'Odstraněné doklady';
			$h = ['#' => '#', 'docNumber' => 'Doklad', 'dateTax' => 'DUZP', 'vatId' => 'DIČ', 'taxCode' => 'Sazba DPH', 'base' => ' Základ', 'tax' => ' Daň'];
			$this->addContent(['type' => 'table', 'header' => $h, 'table' => $changes, 'title' => $title]);
			$cntChanges += count($changes);
		}

		return $cntChanges;
	}

	public function createContentXml_Begin ()
	{
		$this->xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$this->xml .= "<Pisemnost nazevSW=\"Shipard\" verzeSW=\"".__E10_VERSION__."\">\n";
		$this->xml .= "<DPHDP3 verzePis=\"01.02\">\n";
	}

	public function createContentXml_End ()
	{
		$this->xml .= "</DPHDP3>\n</Pisemnost>\n";
	}

	public function createContentXlmDP ()
	{
		$rp = $this->propertiesEngine->properties;

		// -- věta D
		$D = [];
		$this->addDPItem('dokument', $rp['xml'], $D);
		$this->addDPItem('k_uladis', $rp['xml'], $D);
		$this->addDPItem('dapdph_forma', $rp['xml'], $D);
		$this->addDPItem('d_zjist', $rp['xml'], $D);
		$this->addDPItem('typ_platce', $rp['xml'], $D);
		$this->addDPItem('trans', $rp['xml'], $D);
		$this->addDPItem('c_okec', $rp['xml'], $D);

		$this->addDPItem('d_poddp', $rp['xml'], $D);

		$this->addDPItem('rok', $rp['xml'], $D);
		$this->addDPItem('mesic', $rp['xml'], $D);
		$this->addDPItem('ctvrt', $rp['xml'], $D);
		$this->addDPItem('zdobd_od', $rp['xml'], $D);
		$this->addDPItem('zdobd_do', $rp['xml'], $D);

		$this->addXmlRow('VetaD', $D);


		// -- věta P
		$P = [];

		$this->addDPItem('c_pracufo', $rp['xml'], $P);
		$this->addDPItem('c_ufo', $rp['xml'], $P);

		$this->addDPItem('typ_ds', $rp['xml'], $P);
		$this->addDPItem('dic', $rp['xml'], $P);
		$this->addDPItem('zkrobchjm', $rp['xml'], $P);

		$this->addDPItem('prijmeni', $rp['xml'], $P);
		$this->addDPItem('jmeno', $rp['xml'], $P);
		$this->addDPItem('titul', $rp['xml'], $P);

		$this->addDPItem('ulice', $rp['xml'], $P);
		$this->addDPItem('c_pop', $rp['xml'], $P);
		$this->addDPItem('c_orient', $rp['xml'], $P);
		$this->addDPItem('naz_obce', $rp['xml'], $P);
		$this->addDPItem('psc', $rp['xml'], $P);
		$this->addDPItem('stat', $rp['xml'], $P);

		$this->addDPItem('c_telef', $rp['xml'], $P);
		$this->addDPItem('email', $rp['xml'], $P);

		$this->addDPItem('sest_prijmeni', $rp['xml'], $P);
		$this->addDPItem('sest_jmeno', $rp['xml'], $P);
		$this->addDPItem('sest_telef', $rp['xml'], $P);

		$this->addDPItem('zast_typ', $rp['xml'], $P);
		$this->addDPItem('zast_kod', $rp['xml'], $P);
		$this->addDPItem('zast_nazev', $rp['xml'], $P);
		$this->addDPItem('zast_ic', $rp['xml'], $P);
		$this->addDPItem('zast_prijmeni', $rp['xml'], $P);
		$this->addDPItem('zast_jmeno', $rp['xml'], $P);
		$this->addDPItem('zast_dat_nar', $rp['xml'], $P);
		$this->addDPItem('zast_ev_cislo', $rp['xml'], $P);

		$this->addDPItem('opr_prijmeni', $rp['xml'], $P);
		$this->addDPItem('opr_jmeno', $rp['xml'], $P);
		$this->addDPItem('opr_postaveni', $rp['xml'], $P);

		$this->addXmlRow('VetaP', $P);
	}

	function addDPItem ($key, $src, &$dest)
	{
		if (isset($src[$key]))
		{
			$dest[$key] = $src[$key];
			return;
		}

		return;
	}

	function addVItem ($key, $row, $part, &$dest, $excludeBlank = FALSE)
	{
		//$this->data['rowsRounded']['row64']['tax']
		$rid = 'row'.$row;
		if (isset($this->data['rowsRounded'][$rid][$part]))
		{
			if ($excludeBlank && !$this->data['rowsRounded'][$rid][$part])
				return;

			$dest[$key] = $this->data['rowsRounded'][$rid][$part];
			return;
		}

		if ($excludeBlank)
			return;

		$dest[$key] = 0;
		return;
	}

	public function createContentXml_V1()
	{
		$v = [];

		$this->addVItem('obrat23', 1, 'base', $v);
		$this->addVItem('dan23', 1, 'tax', $v);
		$this->addVItem('obrat5', 2, 'base', $v);
		$this->addVItem('dan5', 2, 'tax', $v);
		$this->addVItem('p_zb23', 3, 'base', $v);
		$this->addVItem('dan_pzb23', 3, 'tax', $v);
		$this->addVItem('p_zb5', 4, 'base', $v);
		$this->addVItem('dan_pzb5', 4, 'tax', $v);
		$this->addVItem('p_sl23_e', 5, 'base', $v);
		$this->addVItem('dan_psl23_e', 5, 'tax', $v);
		$this->addVItem('p_sl5_e', 6, 'base', $v);
		$this->addVItem('dan_psl5_e', 6, 'tax', $v);
		$this->addVItem('dov_zb23', 7, 'base', $v);
		$this->addVItem('dan_dzb23', 7, 'tax', $v);
		$this->addVItem('dov_zb5', 8, 'base', $v);
		$this->addVItem('dan_dzb5', 8, 'tax', $v);
		$this->addVItem('p_dop_nrg', 9, 'base', $v);
		$this->addVItem('dan_pdop_nrg', 9, 'tax', $v);
		$this->addVItem('rez_pren23', 10, 'base', $v);
		$this->addVItem('dan_rpren23', 10, 'tax', $v);
		$this->addVItem('rez_pren5', 11, 'base', $v);
		$this->addVItem('dan_rpren5', 11, 'tax', $v);
		$this->addVItem('p_sl23_z', 12, 'base', $v);
		$this->addVItem('dan_psl23_z', 12, 'tax', $v);
		$this->addVItem('p_sl5_z', 13, 'base', $v);
		$this->addVItem('dan_psl5_z', 13, 'tax', $v);

		$this->addXmlRow('Veta1', $v);
	}

	public function createContentXml_V2()
	{
		/*
		 */

		$v = [];

		$this->addVItem('dod_zb', 20, 'base', $v);
		$this->addVItem('pln_sluzby', 21, 'base', $v);
		$this->addVItem('pln_vyvoz', 22, 'base', $v);
		$this->addVItem('dod_dop_nrg', 23, 'base', $v);
		$this->addVItem('pln_zaslani', 24, 'base', $v);
		$this->addVItem('pln_rez_pren', 25, 'base', $v);
		$this->addVItem('pln_ost', 26, 'base', $v);

		$this->addXmlRow('Veta2', $v);
	}

	public function createContentXml_V3()
	{
		$v = [];

		$this->addVItem('tri_pozb', 30, 'base', $v);
		$this->addVItem('tri_dozb', 31, 'base', $v);
		$this->addVItem('dov_osv', 32, 'base', $v);
		$this->addVItem('opr_verit', 33, 'base', $v);
		$this->addVItem('opr_dluz', 34, 'base', $v);

		$this->addXmlRow('Veta3', $v);
	}

	public function createContentXml_V4()
	{
		$v = [];

		$this->addVItem('pln23', 40, 'base', $v);
		$this->addVItem('odp_tuz23_nar', 40, 'tax', $v);
		$this->addVItem('odp_tuz23', 0, 'tax', $v);

		$this->addVItem('pln5', 41, 'base', $v);
		$this->addVItem('odp_tuz5_nar', 41, 'tax', $v);
		$this->addVItem('odp_tuz5', 0, 'tax', $v);

		$this->addVItem('dov_cu', 42, 'base', $v);
		$this->addVItem('odp_cu_nar', 42, 'tax', $v);
		$this->addVItem('odp_cu', 0, 'tax', $v);

		$this->addVItem('nar_zdp23', 43, 'base', $v);
		$this->addVItem('od_zdp23', 43, 'tax', $v);
		$this->addVItem('odkr_zdp23', 0, 'tax', $v);

		$this->addVItem('nar_zdp5', 44, 'base', $v);
		$this->addVItem('od_zdp5', 44, 'tax', $v);
		$this->addVItem('odkr_zdp5', 0, 'tax', $v);

		$this->addVItem('odp_rez_nar', 45, 'tax', $v);
		$this->addVItem('odp_rezim', 0, 'tax', $v);

		$this->addVItem('odp_sum_nar', 46, 'tax', $v);
		$this->addVItem('odp_sum_kr', 0, 'tax', $v);

		$this->addVItem('nar_maj', 47, 'base', $v);
		$this->addVItem('od_maj', 47, 'tax', $v);
		$this->addVItem('odkr_maj', 0, 'tax', $v);

		$this->addXmlRow('Veta4', $v);
	}

	public function createContentXml_V5()
	{
		$v = [];

		$this->addVItem('plnosv_kf', 50, 'base', $v, TRUE);
		$this->addVItem('pln_nkf', 0, 'base', $v, TRUE);
		$this->addVItem('plnosv_nkf', 0, 'base', $v, TRUE);
		$this->addVItem('koef_p20_nov', 0, 'base', $v, TRUE);
		$this->addVItem('koef_p20_vypor', 0, 'base', $v, TRUE);
		$this->addVItem('vypor_odp', 0, 'base', $v, TRUE);
		$this->addVItem('odp_uprav_kf', 0, 'base', $v, TRUE);

		$this->addXmlRow('Veta5', $v);
	}

	public function createContentXml_V6()
	{
		$rp = $this->propertiesEngine->properties;

		$v = [];

		if ((isset($rp['xml']['mesic']) && $rp['xml']['mesic'] === 12) || (isset($rp['xml']['ctvrt']) && $rp['xml']['ctvrt'] === 4))
			$this->addVItem('uprav_odp', 60, 'tax', $v);

		$this->addVItem('dan_vrac', 61, 'tax', $v);
		$this->addVItem('dan_zocelk', 62, 'tax', $v);
		$this->addVItem('odp_zocelk', 63, 'tax', $v);
		$this->addVItem('dano_da', 64, 'tax', $v);
		$this->addVItem('dano_no', 65, 'tax', $v);
		$this->addVItem('dano', 66, 'tax', $v);

		$this->addXmlRow('Veta6', $v);
	}

	public function createContentXml ()
	{
		$this->createContentXml_Begin();
		$this->createContentXlmDP();

		$this->createContentXml_V1();
		$this->createContentXml_V2();
		$this->createContentXml_V3();
		$this->createContentXml_V4();
		$this->createContentXml_V5();
		$this->createContentXml_V6();

		$this->createContentXml_End();

		$fn = utils::tmpFileName('xml', 'priznani-dph');
		file_put_contents($fn, $this->xml);

		return $fn;
	}

	public function loadData ()
	{
		$this->loadData_Sum();
		$this->loadData_Dir(0);
		$this->loadData_Dir(1);
		$this->loadData_ReverseCharge();

		$this->loadInvalidDocs();

		if (!$this->filingNdx && isset($this->reportParams ['filingType']['value']))
		{
			$this->propertiesEngine->properties['xml']['dapdph_forma'] = $this->reportParams ['filingType']['value'];
			unset($this->propertiesEngine->properties['flags']['forma']);
			$this->propertiesEngine->properties['flags']['forma'][$this->reportParams ['filingType']['value']] = 'X';
		}
		$this->data['properties'] = $this->propertiesEngine->properties;

		if (isset($this->data['properties']['xml']['d_zjist']) && $this->data['properties']['xml']['d_zjist'] != '')
		{
			$d_zjist = utils::createDateTime($this->data['properties']['xml']['d_zjist']);
			$this->data['properties']['xml']['d_zjist'] = $d_zjist->format('d.m.Y');
			$this->propertiesEngine->properties['xml']['d_zjist'] = $this->data['properties']['xml']['d_zjist'];
		}

		$this->loadData_OldFiling();

		$this->calcTaxReturn();
	}

	public function loadData_Sum ()
	{
		$q[] = 'SELECT taxDir, taxCode, SUM(base) AS sumBase, SUM(tax) AS sumTax, SUM(total) AS sumTotal';
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatReturn] AS [rows]');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [filing] = %i', $this->filingNdx);

		array_push($q, ' GROUP BY taxDir, taxCode');
		array_push($q, ' ORDER BY taxDir, taxCode');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			$this->data['SUM'][] = $newItem;
		}
	}

	public function loadData_Dir ($dir)
	{
		$did = 'D'.$dir;

		$q[] = 'SELECT [rows].*, docs.docState as docState, ';
		array_push($q, ' docs.person AS personNdx, docs.docType AS docType, persons.fullName AS personName, persons.id AS personId, ');
		array_push($q, ' validity.valid AS valid, validity.msg AS personMsg, validity.revalidate AS personRevalidate');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatReturn] AS [rows]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] as docs ON [rows].document = docs.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] as persons ON docs.person = persons.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_personsValidity] AS validity ON persons.ndx = validity.person');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [filing] = %i', $this->filingNdx);
		array_push($q, ' AND [taxDir] = %i', $dir);

		array_push($q, ' ORDER BY taxCode, docNumber');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			$this->data[$did][] = $newItem;
		}
	}

	public function loadData_ReverseCharge ()
	{
		$reverseChargeTaxCodes = [];
		foreach ($this->taxCodes as $key => $c)
		{
			if ((isset ($c['reverseCharge'])) && ($c['reverseCharge'] === 1))
				$reverseChargeTaxCodes[] = $key;
		}

		if (!count($reverseChargeTaxCodes))
			return;

		$q[] = 'SELECT [rows].*, docs.docState as docState, ';
		array_push($q, ' docs.person AS personNdx, docs.docType AS docType, persons.fullName AS personName, persons.id AS personId, ');
		array_push($q, ' validity.valid AS valid, validity.msg AS personMsg, validity.revalidate AS personRevalidate');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatReturn] AS [rows]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] as docs ON [rows].document = docs.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] as persons ON docs.person = persons.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_personsValidity] AS validity ON persons.ndx = validity.person');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [filing] = %i', $this->filingNdx);
		array_push($q, ' AND [taxCode] IN %in', $reverseChargeTaxCodes);

		array_push($q, ' ORDER BY taxDir, taxCode, docNumber');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			$this->data['rc'.$r['taxDir']][] = $newItem;
		}
	}

	public function loadData_OldFiling ()
	{
		$this->oldVatReturn = NULL;

		if ($this->disableOldVatReturn)
			return;

		if (!isset($this->propertiesEngine->properties['flags']['forma']['D']))
			return;

		$q[] = 'SELECT * FROM [e10doc_taxes_filings] AS filings';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND filings.[report] = %i', $this->taxReportNdx);
		array_push($q, ' ORDER BY filings.ndx DESC');

		$ef = ['0' => 'Aktuální stav'];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
			$ef[$r['ndx']] = $r['title'];

		$oldFilingNdx = 0;
		$prevFilingNdx = -1;
		foreach ($ef as $filingNdx => $filingName)
		{
			if ($prevFilingNdx == $this->filingNdx)
			{
				$oldFilingNdx = $filingNdx;
				break;
			}
			$prevFilingNdx = $filingNdx;
		}

		if (!$oldFilingNdx)
			return;

		$this->oldVatReturn = new \e10doc\taxes\VatReturn\VatReturnReport ($this->app);
		$this->oldVatReturn->disableOldVatReturn = TRUE;
		$this->oldVatReturn->taxReportNdx = $this->taxReportNdx;
		$this->oldVatReturn->filingNdx = $oldFilingNdx;
		$this->oldVatReturn->subReportId = 'ALL';

		$this->oldVatReturn->init();
		$this->oldVatReturn->renderReport();
		$this->oldVatReturn->createReport();
	}

	public function docNumber ($r, &$row)
	{
		if ($this->format === 'pdf')
			return $r['docNumber'];

		$docId = ['table' => 'e10doc.core.heads', 'pk' => $r['document'], 'docAction' => 'edit'];
		$docId['text'] = $r['docNumber'];
		$docId['icon'] = $this->docTypes[$r['docType']]['icon'];
		$docId['title'] = $this->docTypes[$r['docType']]['fullName'].': '.$r['docNumber'];

		$docState = $this->tableDocs->getDocumentState ($r);
		$docStateClass = $this->tableDocs->getDocumentStateInfo ($docState['states'], $r, 'styleClass');
		$row['_options']['cellClasses'] = ['docNumber' => $docStateClass];

		return $docId;
	}

	public function vatId ($r)
	{
		if ($this->format === 'pdf')
			return $r['vatId'];

		if (!$r['personNdx'])
			return '';

		$vatId = ['table' => 'e10.persons.persons', 'pk' => $r['personNdx'], 'docAction' => 'edit'];
		if ($r['vatId'] === '')
		{
			$vatId['text'] = '#'.$r['personId'];
			$vatId['class'] = 'e10-small';
		}
		else
			$vatId['text'] = $r['vatId'];
		$vatId['title'] = $r['personName'];

		$badVatId = FALSE;
		if (isset($this->badVatIds[$r['vatId']]))
			$badVatId = TRUE;

		if ($r['valid'] !== 1 || $badVatId)
			$vatId = [$vatId];

		if ($r['valid'] !== 1)
		{
			if ($r['valid'] == 2)
				$vatId[] = ['text' => '', 'icon' => 'system/iconWarning', 'class' => 'e10-error pull-right'];
			elseif ($r['valid'] == 0)
				$vatId[] = ['text' => '', 'icon' => 'icon-question-circle', 'class' => 'e10-off pull-right'];
		}

		if ($badVatId)
		{
			$vatId[] = ['text' => '', 'icon' => 'icon-file', 'class' => 'e10-error pull-right', 'title' => 'DIČ dokladu nesouhlasí s DIČ v evidenci Osob'];
		}

		return $vatId;
	}

	function addXmlRow ($itemId, $row)
	{
		$xml = '<'.$itemId;
		foreach ($row as $k => $v)
		{
			$xml .= ' '.$k.'="'.utils::es($v).'"';
		}
		$xml .= " />\n";

		$this->xml .= $xml;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
				'text' => 'Uložit jako XML soubor pro elektronické podání', 'icon' => 'system/actionDownload',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'xml',
				'data-filename' => $this->saveAsFileName('xml')
		];
	}

	public function saveAsFileName ($type)
	{
		$fn = $this->taxReportRecData['title'];
		$fn .= '.xml';
		return $fn;
	}

	public function saveReportAs ()
	{
		$this->createContent_All();

		$this->fullFileName = $this->createContentXml();
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
		$this->mimeType = 'application/xml';
	}
}
