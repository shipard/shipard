<?php

namespace E10Doc\CmnBkp;
use \E10\Wizard, \E10\TableForm, \E10\utils;
require_once __SHPD_MODULES_DIR__ . 'e10doc/finance/finance.php';





function createInitStatesBalance ($app, $params)
{
	if (!isset($params['year']))
		return;

	$year = $params['year'];

	$eng = new \e10doc\cmnbkp\libs\InitStatesBalanceEngine ($app);
	$eng->setParams($year);
	$eng->closeDocs = TRUE;
	$eng->run();
}


	/**
	 * CmnBkp_TaxVatReturn_Report
	 *
	 * Výstupní sestava Přiznání DPH
	 *
	 *
	 */

	class CmnBkp_TaxVatReturn_Report extends \e10doc\core\libs\reports\DocReport
	{
		var $taxPeriod = NULL;

		function init ()
		{
			$this->reportId = 'e10doc.cmnbkp.tax-vat-return';
			$this->reportTemplate = 'e10doc.cmnbkp.tax-vat-return';
		}

		function loadData ()
		{
			parent::loadData();

			$this->data['showTaxVatReturnReverseChargeOut'] = FALSE;
			$this->data['showTaxVatReturnReverseChargeIn'] = FALSE;
			$this->data['showTaxVatReturnIntraCommunity'] = FALSE;

			$q = "SELECT [kindItem], [dir] FROM [e10doc_tax_rows]
							WHERE [document] = %i AND ([kindItem] = 'tax-vat-return-reverse-charge' OR [kindItem] = 'tax-vat-return-intra-community')
							GROUP BY [kindItem], [dir]";
			$taxRows = $this->table->db()->query($q, $this->recData ['ndx']);
			foreach ($taxRows as $r)
			{
				if ($r['kindItem'] == 'tax-vat-return-reverse-charge' && $r['dir'] == 1)
					$this->data['showTaxVatReturnReverseChargeOut'] = TRUE;
				if ($r['kindItem'] == 'tax-vat-return-reverse-charge' && $r['dir'] == 0)
					$this->data['showTaxVatReturnReverseChargeIn'] = TRUE;
				if ($r['kindItem'] == 'tax-vat-return-intra-community')
					$this->data['showTaxVatReturnIntraCommunity'] = TRUE;
			}

			$this->taxPeriod = $this->table->db()->query("SELECT * FROM [e10doc_base_taxperiods] WHERE [ndx] = %i", $this->recData ['taxPeriod'])->fetch ();

			if ($this->taxPeriod)
			{
				$endMonthDay = clone $this->taxPeriod['start'];
				$endMonthDay->add(\DateInterval::createFromDateString('1 month -1 day'));
				$endQuarterDay = clone $this->taxPeriod['start'];
				$endQuarterDay->add(\DateInterval::createFromDateString('3 months -1 day'));

				if ($this->taxPeriod['start']->format ('d') == 1 && $endMonthDay == $this->taxPeriod['end'])
				{
					$this->data['taxVatReturn']['taxPeriod']['month'] = $this->taxPeriod['start']->format ('n');
					$this->data['taxVatReturn']['taxPeriod']['year'] = $this->taxPeriod['end']->format ('Y');
				}
				else
					if ($this->taxPeriod['start']->format ('d') == 1 &&
						($this->taxPeriod['start']->format ('m') == 1 ||
							$this->taxPeriod['start']->format ('m') == 4 ||
							$this->taxPeriod['start']->format ('m') == 7 ||
							$this->taxPeriod['start']->format ('m') == 10) &&
						$endQuarterDay == $this->taxPeriod['end'])
					{
						$this->data['taxVatReturn']['taxPeriod']['quarter'] = $this->taxPeriod['end']->format ('n')/3;
						$this->data['taxVatReturn']['taxPeriod']['year'] = $this->taxPeriod['end']->format ('Y');
					}
					else
					{
						$this->data['taxVatReturn']['taxPeriod']['period']['start'] = $this->taxPeriod['start'];
						$this->data['taxVatReturn']['taxPeriod']['period']['end'] = $this->taxPeriod['end'];
					}
			}

			$q = "SELECT * FROM [e10_base_properties] WHERE [tableid] = 'e10doc.core.heads' AND [recid] = %i";
			$docProperties = $this->table->db()->query($q, $this->recData ['ndx']);
			foreach ($docProperties as $p)
				$this->data['taxVatReturn'][$p['group']][$p['property']] = $p['valueString'];
			$this->getEnumText ('e10-CZ-VR', 'e10-CZ-VR-FU');
			$this->getEnumText ('e10-CZ-VR', 'e10-CZ-VR-pracFU');
			$this->getEnumText ('e10-CZ-VR-subjekt', 'e10-CZ-VR-stat');
			$this->data['taxVatReturn']['e10-CZ-VR-subjekt']['e10-CZ-VR-DICbezKoduStatu'] = substr($this->data['taxVatReturn']['e10-CZ-VR-subjekt']['e10-CZ-VR-DIC'], 2);
			switch ($this->data['taxVatReturn']['e10-CZ-VR-subjekt']['e10-CZ-VR-typSubjektu'])
			{
				case 'P' : $this->data['taxVatReturn']['e10-CZ-VR-subjekt']['e10-CZ-VR-typSubjektuP'] = TRUE; break;
				case 'I' : $this->data['taxVatReturn']['e10-CZ-VR-subjekt']['e10-CZ-VR-typSubjektuI'] = TRUE; break;
				case 'S' : $this->data['taxVatReturn']['e10-CZ-VR-subjekt']['e10-CZ-VR-typSubjektuS'] = TRUE; break;
			}
			if ($this->data['taxVatReturn']['e10-CZ-VR']['e10-CZ-VR-novyZdanKod'] == '0')
				$this->data['taxVatReturn']['e10-CZ-VR']['e10-CZ-VR-novyZdanKod'] = '';
			if ($this->data['taxVatReturn']['e10-CZ-VR-zast']['e10-CZ-VR-typZastupce'] == '0')
				$this->data['taxVatReturn']['e10-CZ-VR-zast']['e10-CZ-VR-typZastupce'] = '';
			if ($this->data['taxVatReturn']['e10-CZ-VR-zast']['e10-CZ-VR-kodZastupce'] == '0')
				$this->data['taxVatReturn']['e10-CZ-VR-zast']['e10-CZ-VR-kodZastupce'] = '';
			$this->data['taxVatReturn']['e10-CZ-VR-zast']['e10-CZ-VR-datumNarozeni'] = utils::datef (utils::createDateTime($this->data['taxVatReturn']['e10-CZ-VR-zast']['e10-CZ-VR-datumNarozeni']), '%d');

			$q = "SELECT * FROM [e10doc_tax_rows] WHERE [document] = %i AND [kindItem] <> 'tax-vat-return-items' AND [kindItem] <> 'tax-vat-return-sum'";
			$taxRows = $this->table->db()->query($q, $this->recData ['ndx']);

			$reverseChargeOutLineNumber = 1;
			$reverseChargeOutXmlLineNumber = 1;
			$reverseChargeOutPageNumber = 2;
			$reverseChargeOutTotalBase = 0;

			$reverseChargeInLineNumber = 1;
			$reverseChargeInXmlLineNumber = 1;
			$reverseChargeInPageNumber = 2;
			$reverseChargeInTotalBase = 0;

			$intraCommunityLineNumber = 1;
			$intraCommunityXmlLineNumber = 1;
			$intraCommunityPageNumber = 1;
			$intraCommunityPageTotalBase = 0;
			$intraCommunityTotalBase = 0;
			$intraCommunityTotalCntLines = 0;
			$intraCommunityTotalCntAmounts = 0;
			$this->data['taxVatReturn']['intraCommunity'] = array();
			$newPageReverseChargeOut = ['pageNumber' => $reverseChargeOutPageNumber, 'data' => array()];
			$newPageReverseChargeIn = ['pageNumber' => $reverseChargeInPageNumber, 'data' => array()];
			$newPageIntraCommunity = ['pageNumber' => $intraCommunityPageNumber, 'pageBreak' => FALSE, 'data' => array()];

			foreach ($taxRows as $r)
			{
				// main form
				if ($r['kindItem'] == 'tax-vat-return-rounded-sum')
				{
					$newRow = array();
					$newRow['base'] = $r['base'];
					$newRow['tax'] = $r['tax'];
					if (isset($this->data['taxVatReturn']['roundedSum']['row'.$r['row']]))
					{
						$this->data['taxVatReturn']['roundedSum']['row'.$r['row']]['base'] += $newRow['base'];
						$this->data['taxVatReturn']['roundedSum']['row'.$r['row']]['tax'] += $newRow['tax'];
					}
					else
						$this->data['taxVatReturn']['roundedSum']['row'.$r['row']] = $newRow;
				}

				// reverse charge
				if ($r['kindItem'] == 'tax-vat-return-reverse-charge')
				{
					if ($reverseChargeInLineNumber > 48 && $r['dir'] != 1)
					{
						$this->data['taxVatReturn']['reverseChargeIn']['pages'][] = $newPageReverseChargeIn;
						$reverseChargeInLineNumber = 1;
						$reverseChargeInPageNumber++;
						$newPageReverseChargeIn = ['pageNumber' => $reverseChargeInPageNumber, 'data' => array()];
					}
					if ($reverseChargeOutLineNumber > 48 && $r['dir'] == 1)
					{
						$this->data['taxVatReturn']['reverseChargeOut']['pages'][] = $newPageReverseChargeOut;
						$reverseChargeOutLineNumber = 1;
						$reverseChargeOutPageNumber++;
						$newPageReverseChargeOut = ['pageNumber' => $reverseChargeOutPageNumber, 'data' => array()];
					}

					$newRow = array();
					$newRow['base'] = $r['base'];
					$newRow['countryCode'] = $r['countryCode'];
					$newRow['pvin'] = $r['pvin'];
					$newRow['shortpvin'] = $r['shortpvin'];
					$newRow['code'] = $r['code'];
					$newRow['dateTax'] = $r['dateTax'];
					$newRow['amount'] = $r['amount'];
					$newRow['unit'] = $r['unit'];
					if ($r['unit'] == 'kg')
						$newRow['unitText'] = 'kilogram';
					else
						$newRow['unitText'] = '';


					if ($r['dir'] == 1)
					{
						$reverseChargeOutTotalBase += $r['base'];
						$newRow['lineNumber'] = $reverseChargeOutLineNumber++;
						$newRow['xmlLineNumber'] = $reverseChargeOutXmlLineNumber++;
						$newPageReverseChargeOut['data'][] = $newRow;
					}
					else
					{
						$reverseChargeInTotalBase += $r['base'];
						$newRow['lineNumber'] = $reverseChargeInLineNumber++;
						$newRow['xmlLineNumber'] = $reverseChargeInXmlLineNumber++;
						$newPageReverseChargeIn['data'][] = $newRow;
					}
				}

				// intra community
				if ($r['kindItem'] == 'tax-vat-return-intra-community')
				{
					if ($intraCommunityLineNumber > 20)
					{
						$newPageIntraCommunity['totalBase'] = $intraCommunityPageTotalBase;
						if ($intraCommunityPageNumber == 1)
							$newPageIntraCommunity['taxVatReturn'] = array_merge ($this->data['taxVatReturn']);
						$this->data['taxVatReturn']['intraCommunity']['pages'][] = $newPageIntraCommunity;
						$intraCommunityLineNumber = 1;
						$intraCommunityPageNumber++;
						$intraCommunityPageTotalBase = 0;
						$newPageIntraCommunity = ['pageNumber' => $intraCommunityPageNumber, 'pageBreak' => TRUE, 'data' => array()];
					}

					$newRow = array();
					$newRow['lineNumber'] = $intraCommunityLineNumber++;
					$newRow['xmlLineNumber'] = $intraCommunityXmlLineNumber++;
					$newRow['base'] = $r['base'];
					$intraCommunityPageTotalBase += $r['base'];
					$intraCommunityTotalBase += $r['base'];
					$newRow['countryCode'] = $r['countryCode'];
					$newRow['pvin'] = $r['pvin'];
					$newRow['shortpvin'] = $r['shortpvin'];
					$newRow['code'] = $r['code'];
					$newRow['amount'] = $r['amount'];
					$intraCommunityTotalCntAmounts += $r['amount'];
					$newPageIntraCommunity['data'][] = $newRow;
					$intraCommunityTotalCntLines++;
				}
			}

			while ($reverseChargeOutLineNumber < 49)
			{
				$newPageReverseChargeOut['data'][] = array();
				$reverseChargeOutLineNumber++;
			}
			$this->data['taxVatReturn']['reverseChargeOut']['pages'][] = $newPageReverseChargeOut;
			while ($reverseChargeInLineNumber < 49)
			{
				$newPageReverseChargeIn['data'][] = array();
				$reverseChargeInLineNumber++;
			}
			$this->data['taxVatReturn']['reverseChargeIn']['pages'][] = $newPageReverseChargeIn;

			while ($intraCommunityLineNumber < 21)
				$newPageIntraCommunity['data'][] = ['lineNumber' => $intraCommunityLineNumber++];

			$this->data['taxVatReturn']['intraCommunity']['cntPages'] = $intraCommunityPageNumber;
			$this->data['taxVatReturn']['intraCommunity']['cntAmounts'] = $intraCommunityTotalCntAmounts;
			$this->data['taxVatReturn']['intraCommunity']['cntLines'] = $intraCommunityTotalCntLines;
			$this->data['taxVatReturn']['intraCommunity']['totalBase'] = $intraCommunityTotalBase;
			$newPageIntraCommunity['totalBase'] = $intraCommunityPageTotalBase;
			if ($intraCommunityPageNumber == 1)
				$newPageIntraCommunity['taxVatReturn'] = array_merge ($this->data['taxVatReturn']);
			$this->data['taxVatReturn']['intraCommunity']['pages'][] = $newPageIntraCommunity;

			$this->data['taxVatReturn']['reverseChargeOut']['totalBase'] = $reverseChargeOutTotalBase;
			$this->data['taxVatReturn']['reverseChargeIn']['totalBase'] = $reverseChargeInTotalBase;

			foreach ($this->data['taxVatReturn']['reverseChargeOut']['pages'] as $key => $page)
				$this->data['taxVatReturn']['reverseChargeOut']['pages'][$key]['cntPages'] = $reverseChargeOutPageNumber;
			foreach ($this->data['taxVatReturn']['reverseChargeIn']['pages'] as $key => $page)
				$this->data['taxVatReturn']['reverseChargeIn']['pages'][$key]['cntPages'] = $reverseChargeInPageNumber;
			foreach ($this->data['taxVatReturn']['intraCommunity']['pages'] as $key => $page)
				$this->data['taxVatReturn']['intraCommunity']['pages'][$key]['cntPages'] = $intraCommunityPageNumber;

			$this->data['taxVatReturn']['roundedSum']['row46']['tax'] = $this->calcLines ('roundedSum', 'tax', [40, 41, 42, 43, 44, 45]);
			$this->data['taxVatReturn']['roundedSum']['row62']['tax'] = $this->calcLines ('roundedSum', 'tax', [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, -61]);
			$this->data['taxVatReturn']['roundedSum']['row63']['tax'] = $this->calcLines ('roundedSum', 'tax', [46, 52, 53, 60]);
			$totalTaxVatReturn = $this->calcLines ('roundedSum', 'tax', [62, -63]);
			if ($totalTaxVatReturn < 0)
			{
				$this->data['taxVatReturn']['roundedSum']['row64']['tax'] = 0;
				$this->data['taxVatReturn']['roundedSum']['row65']['tax'] = -$totalTaxVatReturn;
			}
			else
			{
				$this->data['taxVatReturn']['roundedSum']['row64']['tax'] = $totalTaxVatReturn;
				$this->data['taxVatReturn']['roundedSum']['row65']['tax'] = 0;
			}
			$this->data['taxVatReturn']['roundedSum']['row66']['tax'] = 0;
		}

		function getEnumText ($group, $property)
		{
			$value = $this->data['taxVatReturn'][$group][$property];
			$enumValue = $this->table->db()->query("SELECT a.[fullName] as fullName FROM e10_base_propdefsenum as a INNER JOIN e10_base_propdefs as b ON (a.property = b.ndx) WHERE a.id = %s AND b.id = %s", $value, $property)->fetch ();
			$this->data['taxVatReturn'][$group][$property.'text'] = $enumValue['fullName'];
		}

		function calcLines ($kindItem, $valueType, $lines)
		{
			$result = 0;
			foreach ($lines as $line)
			{
				$signMinus = FALSE;
				$l = $line;
				if ($line < 0)
				{
					$signMinus = TRUE;
					$l = -$line;
				}
				if (isset($this->data['taxVatReturn'][$kindItem]['row'.$l][$valueType]))
				{
					if ($signMinus)
						$result -= $this->data['taxVatReturn'][$kindItem]['row'.$l][$valueType];
					else
						$result += $this->data['taxVatReturn'][$kindItem]['row'.$l][$valueType];
				}
			}
			return $result;
		}

		public function createToolbarSaveAs (&$printButton)
		{
			$printButton['dropdownMenu'][] = [
				'text' => 'Přiznání DPH (.xml)', 'icon' => 'system/actionDownload',
				'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/cz-tax-vat-return-xml', 'data-filename' => $this->saveAsFileName('cz/cz-tax-vat-return-xml'),
				'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.cmnbkp.CmnBkp_TaxVatReturn_Report', 'data-pk' => $this->recData['ndx']
			];
			if ($this->data['showTaxVatReturnReverseChargeOut'] != FALSE)
			{
				$printButton['dropdownMenu'][] = [
					'text' => 'Výpis z evidence pro přiznání DPH (dodavatel) (.xml)', 'icon' => 'system/actionDownload',
					'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/cz-tax-vat-return-reverse-charge-out-xml', 'data-filename' => $this->saveAsFileName('cz/cz-tax-vat-return-reverse-charge-out-xml'),
					'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.cmnbkp.CmnBkp_TaxVatReturn_Report', 'data-pk' => $this->recData['ndx']
				];
			}
			if ($this->data['showTaxVatReturnReverseChargeIn'] != FALSE)
			{
				$printButton['dropdownMenu'][] = [
					'text' => 'Výpis z evidence pro přiznání DPH (odběratel) (.xml)', 'icon' => 'system/actionDownload',
					'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/cz-tax-vat-return-reverse-charge-in-xml', 'data-filename' => $this->saveAsFileName('cz/cz-tax-vat-return-reverse-charge-in-xml'),
					'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.cmnbkp.CmnBkp_TaxVatReturn_Report', 'data-pk' => $this->recData['ndx']
				];
			}
			if ($this->data['showTaxVatReturnIntraCommunity'] != FALSE)
			{
				$printButton['dropdownMenu'][] = [
					'text' => 'Souhrnné hlášení pro přiznání DPH (.xml)', 'icon' => 'system/actionDownload',
					'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/cz-tax-vat-return-intra-community-xml', 'data-filename' => $this->saveAsFileName('cz/cz-tax-vat-return-intra-community-xml'),
					'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.cmnbkp.CmnBkp_TaxVatReturn_Report', 'data-pk' => $this->recData['ndx']
				];
			}
		}

		public function saveReportAs ()
		{
			$data = $this->renderTemplate ($this->reportTemplate, $this->saveAs);

			$fn = utils::tmpFileName ('xml');
			file_put_contents($fn, $data);
			$this->fullFileName = $fn;
			$this->saveFileName = $this->saveAsFileName ($this->saveAs);
			$this->mimeType = 'application/xml';
		}

		public function saveAsFileName ($type)
		{
			$periodName = str_replace(' ', '', $this->taxPeriod['fullName']);
			$fn = 'DPH '.$periodName.' - ';
			switch ($type)
			{
				case 'cz/cz-tax-vat-return-xml': 										$fn .= 'Přiznání'; break;
				case 'cz/cz-tax-vat-return-reverse-charge-out-xml': $fn .= 'Výpis PDP Dodavatel'; break;
				case 'cz/cz-tax-vat-return-reverse-charge-in-xml': 	$fn .= 'Výpis PDP Odběratel'; break;
				case 'cz/cz-tax-vat-return-intra-community-xml': 		$fn .= 'Souhrnné hlášení'; break;
			}

			$fn .= '.xml';

			return $fn;
		}
	} // class CmnBkp_TaxVatReturn_Report



function createVATReturn ($app, $params)
{
	$eng = new VATReturnEngine ($app);
	$vatPeriods = $app->cfgItem ('e10doc.vatPeriods');
	$vp = utils::searchArray($vatPeriods, 'id', $params ['vatPeriod']);
	if ($vp !== NULL)
	{
		$eng->setParams($vp['ndx']);
		if (isset($params['taxOfficeId']) && isset($params['dataPackageInstaller']))
			$eng->taxOfficeNdx = $params['dataPackageInstaller']->primaryKeys[$params['taxOfficeId']];
		if (isset($params['dateIssue']))
			$eng->dateIssue = utils::createDateTime($params['dateIssue']);
		if (isset($params['dataPackageInstaller']))
			$eng->closeDocument = TRUE;
		$eng->run();

		if (isset($params['dataPackageInstaller']))
			$params['dataPackageInstaller']->primaryKeys['LAST-VAT-RETURN'] = $eng->taxReturnDocNdx;
	}
}


