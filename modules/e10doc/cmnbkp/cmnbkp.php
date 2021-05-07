<?php

namespace E10Doc\CmnBkp;
use \E10\Wizard, \E10\TableForm, \E10\utils;






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
 * CmnBkpReport
 *
 * Výstupní sestava Obecného účetního dokladu
 *
 *
 */

class CmnBkpReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		$this->reportId = 'e10doc.cmnbkp.cmnbkp';
		$this->reportTemplate = 'e10doc.cmnbkp.cmnbkp';
	}
} // class CmnBkpReport


/**
 * CmnBkp_SetOff_Report
 *
 * Výstupní sestava Zápočtu
 *
 *
 */

class CmnBkp_SetOff_Report extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10doc.cmnbkp.set-off');
	}
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
				'text' => 'Přiznání DPH (.xml)', 'icon' => 'icon-download',
				'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/cz-tax-vat-return-xml', 'data-filename' => $this->saveAsFileName('cz/cz-tax-vat-return-xml'),
				'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.cmnbkp.CmnBkp_TaxVatReturn_Report', 'data-pk' => $this->recData['ndx']
			];
			if ($this->data['showTaxVatReturnReverseChargeOut'] != FALSE)
			{
				$printButton['dropdownMenu'][] = [
					'text' => 'Výpis z evidence pro přiznání DPH (dodavatel) (.xml)', 'icon' => 'icon-download',
					'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/cz-tax-vat-return-reverse-charge-out-xml', 'data-filename' => $this->saveAsFileName('cz/cz-tax-vat-return-reverse-charge-out-xml'),
					'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.cmnbkp.CmnBkp_TaxVatReturn_Report', 'data-pk' => $this->recData['ndx']
				];
			}
			if ($this->data['showTaxVatReturnReverseChargeIn'] != FALSE)
			{
				$printButton['dropdownMenu'][] = [
					'text' => 'Výpis z evidence pro přiznání DPH (odběratel) (.xml)', 'icon' => 'icon-download',
					'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/cz-tax-vat-return-reverse-charge-in-xml', 'data-filename' => $this->saveAsFileName('cz/cz-tax-vat-return-reverse-charge-in-xml'),
					'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.cmnbkp.CmnBkp_TaxVatReturn_Report', 'data-pk' => $this->recData['ndx']
				];
			}
			if ($this->data['showTaxVatReturnIntraCommunity'] != FALSE)
			{
				$printButton['dropdownMenu'][] = [
					'text' => 'Souhrnné hlášení pro přiznání DPH (.xml)', 'icon' => 'icon-download',
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


/**
 * VATReturnEngine
 *
 */

class VATReturnEngine extends \E10\Utility
{
	var $vatPeriodNdx;
	var $vatPeriod;
	var $tableDocs;
	var $tableRows;

	var $taxReturnDocNdx = 0;

	var $closeDocument = FALSE;
	var $taxOfficeNdx = 0;
	var $dateIssue = NULL;

	function createDocHead ()
	{
		// dbcounter id
		$dbCounter = $this->db()->query ('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s AND [activitiesGroup] = %s',
																		 'cmnbkp', 'tax')->fetch();
		if (!isset ($dbCounter['ndx']))
		{
			error_log ("ERROR - VATReturnEngine: dbCounter not found.");
			return FALSE;
		}

		$docDate = $this->vatPeriod['end']->format ('Y-m-d');

		$q = 'SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s AND [dbCounter] = %i AND [taxPeriod] = %i';
		$existedDocs = $this->db()->query ($q, 'cmnbkp', $dbCounter['ndx'], $this->vatPeriodNdx)->fetch();
		if ($existedDocs['docState'] === 4000)
		{
			return FALSE;
		}

		if ($existedDocs['docState'] === 1000 || $existedDocs['docState'] === 1200 || $existedDocs['docState'] === 8000)
		{ // new/confirmed/edited
			$docH = $existedDocs->toArray ();
		}
		else
		{
			$docH = array ();
			$docH ['docType'] = 'cmnbkp';
			$this->tableDocs->checkNewRec ($docH);
		}

		// docKind
		$docKinds = $this->app->cfgItem ('e10.docs.kinds', FALSE);
		$dk = utils::searchArray($docKinds, 'activity', 'taxVatReturn');

		$title = 'Přiznání DPH '.$this->vatPeriod['fullName'];

		$docH ['taxPeriod']					= $this->vatPeriodNdx;
		$docH ['dateAccounting']		= $docDate;
		$docH ['title'] 						= $title;
		$docH ['taxCalc']						= 0;
		$docH ['dbCounter']					= $dbCounter['ndx'];
		$docH ['docKind']						= $dk['ndx'];

		if ($this->taxOfficeNdx)
			$docH ['person'] = $this->taxOfficeNdx;
		if ($this->dateIssue)
			$docH ['dateIssue'] = $this->dateIssue;

		return $docH;
	}

	function createDocRows ($head)
	{
		// sumární přehled DPH na haléře
		$vatReport = new \E10Doc\Finance\reportVAT ($this->app);
		$vatReport->taxPeriod = $this->vatPeriodNdx;
		$vatReportData = $vatReport->createData_Summary();

		// sumární přehled DPH zaokrouhleně
		$vatReportRounded = new \E10Doc\Finance\reportVAT ($this->app);
		$vatReportRounded->taxPeriod = $this->vatPeriodNdx;
		$vatReportRounded->rounding = 1;
		$vatReportRoundedData = $vatReportRounded->createData_Summary();

		// položkový přehled DPH
		$vatReportItems = new \E10Doc\Finance\reportVAT ($this->app);
		$vatReportItems->taxPeriod = $this->vatPeriodNdx;
		$vatReportItemsData = $vatReportItems->createData_Items2();

		// přehled DPH - PDP
		$vatReportReverseCharge = new \E10Doc\Finance\reportVAT ($this->app);
		$vatReportReverseCharge->taxPeriod = $this->vatPeriodNdx;
		$vatReportReverseChargeData = $vatReportReverseCharge->createData_RevCharge();

		// přehled DPH - souhrnné hlášení
		$vatReportIntraCommunity = new \E10Doc\Finance\reportVAT ($this->app);
		$vatReportIntraCommunity->taxPeriod = $this->vatPeriodNdx;
		$vatReportIntraCommunityData = $vatReportIntraCommunity->createData_IntraCommunity();

		$newRows = array();
		$vatInput = 0;
		$vatOutput = 0;
		$vatInputR = 0;
		$vatOutputR = 0;

		forEach ($vatReportData as $r)
		{
			if (!isset($r['tax']) || $r['tax'] == 0)
				continue;
			if (!isset($r['taxCode']) || $r['taxCode'] == '')
				continue;

			$newRow = array ();

			$newRow ['operation'] = 1099999;
			$newRow ['debsAccountId'] = '343'.$r['taxCode'];
			$newRow ['item'] = 0;
			$newRow ['text'] = $r['title'];
			$newRow ['quantity'] = 1;
			$newRow ['priceItem'] = 0;
			$newRow ['person'] = 0;

			$newRow ['credit'] = 0.0;
			$newRow ['debit'] = 0.0;

			if ($r['dir'] === 0)
				$newRow ['credit'] = $r['tax'];
			else
				$newRow ['debit'] = $r['tax'];

			$vatInput += $newRow ['credit'];
			$vatOutput += $newRow ['debit'];

			$newRows[] = $newRow;
		}

		forEach ($vatReportRoundedData as $r)
		{
			if (!isset($r['tax']) || $r['tax'] == 0)
				continue;
			if (!isset($r['taxCode']) || $r['taxCode'] == '')
				continue;

			if ($r['dir'] === 0)
				$vatInputR += round ($r['tax']);
			else
				$vatOutputR += round ($r['tax']);
		}

		$vatAmount = $vatOutput - $vatInput;
		$vatAmountR = $vatOutputR - $vatInputR;
		if ($vatAmountR > 0)
		{
			$newRow = array ('operation' => 1099998, 'debit' => 0.0, 'credit' => $vatAmountR, 'text' => 'Odvod DPH '.$this->vatPeriod['fullName']);
			$newRow = array_merge($newRow, $this->findRowData('odvod-dph', $head['owner'], $head['person']));
			$newRows[] = $newRow;
		}
		if ($vatAmountR < 0)
		{
			$newRow = array ('operation' => 1099998, 'credit' => 0.0, 'debit' => - $vatAmountR, 'text' => 'Odpočet DPH '.$this->vatPeriod['fullName']);
			$newRow = array_merge ($newRow, $this->findRowData('odpocet-dph', $head['owner'], $head['person']));
			$newRows[] = $newRow;
		}

		$amountRounding = $vatAmountR - $vatAmount;
		if ($amountRounding < 0)
		{
			$newRow = array ('operation' => 1099998, 'debit' => 0.0, 'credit' => -$amountRounding, 'text' => 'Zaokrouhlení');
			$newRow = array_merge ($newRow, $this->findRowData('zaokrouhleni-vynosy', $head['owner'], $head['person']));
			$newRows[] = $newRow;
		}
		if ($amountRounding > 0)
		{
			$newRow = array ('operation' => 1099998, 'credit' => 0.0, 'debit' => $amountRounding, 'text' => 'Zaokrouhlení');
			$newRow = array_merge ($newRow, $this->findRowData('zaokrouhleni-naklady', $head['owner'], $head['person']));
			$newRows[] = $newRow;
		}

		$newTaxRows = [
					'tax-vat-return-sum',
					'tax-vat-return-rounded-sum',
					'tax-vat-return-items',
					'tax-vat-return-reverse-charge',
					'tax-vat-return-intra-community'
		];
		forEach ($vatReportData as $r) // sumární přehled DPH na haléře
		{
			if (!isset ($r['taxCode']))
				continue;

			$newTaxRow = array ();
			$newTaxRow['base'] = $r['base'];
			$newTaxRow['tax'] = $r['tax'];
			$newTaxRow['taxCode'] = $r['taxCode'];
			$newTaxRow['row'] = $r['row'];
			$newTaxRow['accountId'] = '343'.$r['taxCode'];
			$newTaxRow['dir'] = $r['dir'];
			$newTaxRows['tax-vat-return-sum'][] = $newTaxRow;
		}
		forEach ($vatReportRoundedData as $r) // sumární přehled DPH zaokrouhleně
		{
			if (!isset ($r['taxCode']))
				continue;

			$newTaxRow = array ();
			$newTaxRow['base'] = $r['base'];
			$newTaxRow['tax'] = $r['tax'];
			$newTaxRow['taxCode'] = $r['taxCode'];
			$newTaxRow['row'] = $r['row'];
			$newTaxRow['accountId'] = '343'.$r['taxCode'];
			$newTaxRow['dir'] = $r['dir'];
			$newTaxRows['tax-vat-return-rounded-sum'][] = $newTaxRow;
		}
		forEach ($vatReportItemsData as $r) // položkový přehled DPH
		{
			if (!isset ($r['taxCode']))
				continue;

			$newTaxRow = array ();
			$newTaxRow['base'] = $r['base'];
			$newTaxRow['tax'] = $r['tax'];
			$newTaxRow['taxCode'] = $r['taxCode'];
			$newTaxRow['row'] = $r['row'];
			$newTaxRow['accountId'] = '343'.$r['taxCode'];
			$newTaxRow['dir'] = $r['dir'];
			$newTaxRow['docNumber'] = $r['document']['text'];
			$newTaxRow['docType'] = $r['docType'];
			$newTaxRow['dateTax'] = $r['dateTax'];
			$newTaxRow['countryCode'] = substr ($r['pvin'], 0, 2);
			$newTaxRow['pvin'] = $r['pvin'];
			$newTaxRow['shortpvin'] = substr ($r['pvin'], 2);
			$newTaxRows['tax-vat-return-items'][] = $newTaxRow;
		}
		forEach ($vatReportReverseChargeData['data'] as $dirId => $dataDirs) // přehled DPH - PDP
		{
			if (count ($dataDirs) == 0)
				continue;

			forEach ($dataDirs as $codeId => $rows)
			{
				forEach ($rows as $r)
				{
					$newTaxRow = array ();
					$newTaxRow['base'] = $r['base'];
					$newTaxRow['tax'] = 0.0;
					$newTaxRow['taxCode'] = 0;
					$newTaxRow['row'] = 0;
					$newTaxRow['accountId'] = '';
					$newTaxRow['dir'] = $dirId;
					$newTaxRow['docNumber'] = $r['docNumber'];
					$newTaxRow['docType'] = $r['docType'];
					$newTaxRow['dateTax'] = $r['dateTax'];
					$newTaxRow['countryCode'] = substr ($r['pvin'], 0, 2);
					$newTaxRow['pvin'] = $r['pvin'];
					$newTaxRow['shortpvin'] = substr ($r['pvin'], 2);
					$newTaxRow['code'] = $r['code'];
					$newTaxRow['amount'] = $r['amount'];
					$newTaxRow['unit'] = $r['unit'];
					$newTaxRows['tax-vat-return-reverse-charge'][] = $newTaxRow;
				}
			}
		}
		forEach ($vatReportIntraCommunityData as $r) // přehled DPH - souhrnné hlášení
		{
			$newTaxRow = array ();
			$newTaxRow['base'] = $r['amount'];
			$newTaxRow['tax'] = 0.0;
			$newTaxRow['taxCode'] = 0;
			$newTaxRow['row'] = 0;
			$newTaxRow['accountId'] = '';
			$newTaxRow['dir'] = 1;
			$newTaxRow['countryCode'] = substr ($r['pvin'], 0, 2);
			$newTaxRow['pvin'] = $r['pvin'];
			$newTaxRow['shortpvin'] = substr ($r['pvin'], 2);
			$newTaxRow['code'] = $r['code'];
			$newTaxRow['amount'] = $r['cnt'];
			$newTaxRows['tax-vat-return-intra-community'][] = $newTaxRow;
		}

		$allDocRows = ['docRows' => $newRows, 'taxRows' => $newTaxRows];
		return $allDocRows;
	}

	function setParams ($vatPeriodNdx)
	{
		$this->vatPeriodNdx = intval($vatPeriodNdx);
		$this->vatPeriod = $this->app->loadItem ($this->vatPeriodNdx, 'e10doc.base.taxperiods');
	}

	function run ()
	{
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);

		$this->app->db->begin();

		$docHead = $this->createDocHead ();
		//if ($docHead !== FALSE)
		{
			$docRows = $this->createDocRows($docHead);
			//if (count($docRows) !== 0)
				$this->save ($docHead, $docRows);
		}
		$this->app->db->commit();
	}

	protected function save ($head, $rows)
	{
		if (!isset ($head['ndx']))
		{
			$docNdx = $this->tableDocs->dbInsertRec ($head);
		}
		else
		{
			$docNdx = $head['ndx'];
			$this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
			$this->db()->query ('DELETE FROM [e10doc_tax_rows] WHERE [document] = %i', $docNdx);
			$this->tableDocs->dbUpdateRec ($head);
		}

		$this->taxReturnDocNdx = $docNdx;

		$f = $this->tableDocs->getTableForm ('edit', $docNdx);
		if ($f->checkAfterSave())
			$this->tableDocs->dbUpdateRec ($f->recData);

		forEach ($rows['docRows'] as $docRow)
		{
			$docRow['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($docRow, $f->recData);
		}

		forEach ($rows['taxRows'] as $kindKey => $kind)
		{
			if (is_array ($kind))
			{
				forEach ($kind as $taxRow)
				{
					$taxRow['document'] = $docNdx;
					$taxRow['kindItem'] = $kindKey;
					$taxRow['total'] = $taxRow['base']+$taxRow['tax'];
					$this->db()->query ("INSERT INTO [e10doc_tax_rows]", $taxRow);
				}
			}
		}

		$prevDocHead = $this->db()->query ('SELECT ndx FROM [e10doc_core_heads] WHERE [docState] = 4000 AND [docType] = %s AND [activity] = %s ORDER BY [dateAccounting] DESC LIMIT 1', 'cmnbkp', 'taxVatReturn')->fetch();

		if ($prevDocHead['ndx'] != 0)
		{
			$prevDoc = $this->tableDocs->loadItem ($prevDocHead);
			$q = 'SELECT * from [e10_base_properties] WHERE [tableid] = %s AND [recid] = %i';
			$prevDoc['properties'] = $this->app->db()->query ($q, 'e10doc.core.heads', $prevDoc['ndx']);

			if ($head['person'] == 0)
				$f->recData['person'] = $prevDoc['person'];

			foreach($prevDoc['properties'] as $p)
			{
				$property = ['property' => $p['property'], 'group' => $p['group'], 'tableid' => 'e10doc.core.heads', 'recid' => $docNdx, 'valueString' => $p['valueString']];
				$docProperty = $this->db()->query ('SELECT * FROM [e10_base_properties] WHERE [property] = %s AND [group] = %s AND [tableid] = %s AND [recid] = %i',
					$property['property'], $property['group'], $property['tableid'], $property['recid'])->fetch();

				if ($docProperty['ndx'] == 0)
					$this->db()->query ("INSERT INTO [e10_base_properties]", $property);
				else
					if ($docProperty['ndx'] != 0 AND $docProperty['valueString'] == '')
						$this->db()->query ("UPDATE [e10_base_properties] SET ", $property, "WHERE [ndx] = %i", $docProperty['ndx']);
			}
		}

		if ($this->closeDocument)
		{
			$f->recData ['docState'] = 4000;
			$f->recData ['docStateMain'] = 2;
			$this->tableDocs->checkDocumentState($f->recData);
		}

		$f->checkAfterSave();
		$this->tableDocs->dbUpdateRec ($f->recData);
		$this->tableDocs->checkAfterSave2 ($f->recData);
	}

	function findRowData ($id, $ownerNdx, $personHead)
	{
		$rowData = array ();

		$rowData['item'] = 0;
		$item = $this->app->db()->fetch('SELECT [ndx] FROM [e10_witems_items] WHERE id = %s LIMIT 1', $id);
		if (isset($item['ndx']) && ($item['ndx'] > 0))
			$rowData['item'] = $item['ndx'];

		if ($id == 'odvod-dph' || $id == 'odpocet-dph')
		{
			$dateDue = clone $this->vatPeriod['end'];
			if ($id == 'odvod-dph')
				$dateDue->add(\DateInterval::createFromDateString('25 days'));
			else
				$dateDue->add(\DateInterval::createFromDateString('2 months'));
			$rowData['dateDue'] = $dateDue;
			$rowData['symbol2'] = '705'.preg_replace("/[^0-9,.]/", "", $this->vatPeriod['id']);
			$rowData = array_merge($rowData, $this->findPrevDocRowData ($rowData['item'], $ownerNdx, $personHead, TRUE));
		}
		else
			$rowData = array_merge($rowData, $this->findPrevDocRowData ($rowData['item'], $ownerNdx, $personHead, FALSE));


		return $rowData;
	}

	function findPrevDocRowData ($itemNdx, $ownerNdx, $personHead, $balanceRow)
	{
		$rowData = ['person' => 0, 'symbol1' => '', 'symbol3' => '', 'bankAccount' => '', 'centre' => 0];

		if ($this->taxOfficeNdx)
		{
			if ($balanceRow)
				$rowData['person'] = $this->taxOfficeNdx;
		}
		else
		{
			$q[] = 'SELECT r.[person] as person, r.[symbol1] as symbol1, r.[symbol3] as symbol3, r.[bankAccount] as bankAccount, r.[centre] as centre';
			array_push ($q, ' FROM [e10doc_core_heads] as h');
			array_push ($q, ' INNER JOIN [e10doc_core_rows] as r ON (h.ndx = r.document)');
			array_push ($q, ' WHERE h.[docState] = %i', 4000);
			array_push ($q, ' AND h.[docType] = %s', 'cmnbkp');
			array_push ($q, ' AND h.[activity] = %s', 'taxVatReturn');
			array_push ($q, ' AND r.[item] = %i', $itemNdx);
			array_push ($q, ' ORDER BY h.[dateAccounting] DESC');
			array_push ($q, ' LIMIT 1');
			$rowPrevDocData = $this->app->db()->fetch($q);
			if (isset($rowPrevDocData))
			{
				$rowData['symbol1'] = $rowPrevDocData['symbol1'];
				$rowData['symbol3'] = $rowPrevDocData['symbol3'];
				$rowData['bankAccount'] = $rowPrevDocData['bankAccount'];
				$rowData['centre'] = $rowPrevDocData['centre'];
				if ($rowPrevDocData['person'] > 0)
					$rowData['person'] = $rowPrevDocData['person'];
				else
				{
					if ($balanceRow)
						$rowData['person'] = $personHead;
				}
			}
		}

		if ($rowData['symbol1'] == '' && $balanceRow)
		{
			$taxid = $this->app->db()->fetch('SELECT [valueString] FROM [e10_base_properties] WHERE ([property]=%s  AND [group]=%s AND [tableid]=%s AND [recid]=%i) LIMIT 1',
				'taxid', 'ids', 'e10.persons.persons', $ownerNdx);
			if (isset($taxid['valueString']) && ($taxid['valueString'] != ''))
				$rowData['symbol1'] = substr($taxid['valueString'], 2);
		}
		if ($rowData['symbol3'] == '' && $balanceRow)
		{
			$rowData['symbol3'] = '1148';
		}

		return $rowData;
	}
}


/**
 * VATReturnWizard
 *
 */

class VATReturnWizard extends Wizard
{
	public function __construct($app, $options = NULL)
	{
		parent::__construct($app, $options);
		$this->dirtyColsReferences['vatPeriod'] = 'e10doc.base.taxperiods';
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt ();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->table = $this->app->table ('e10doc.base.taxperiods');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addInputIntRef ('vatPeriod', 'e10doc.base.taxperiods', 'Přiznání DPH');
		$this->closeForm ();
	}

	public function doIt ()
	{
		$eng = new VATReturnEngine ($this->app);
		if ($this->recData['vatPeriod'])
		{
			$eng->setParams($this->recData['vatPeriod']);
			$eng->run();
		}

		$this->stepResult ['close'] = 1;
	}
}

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


