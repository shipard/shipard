<?php

namespace E10Doc\Finance;
use \E10\utils, e10doc\core\libs\E10Utils;


/**
 * reportVAT
 *
 */

class reportVAT extends \e10doc\core\libs\reports\GlobalReport
{
	public $taxPeriod = 0;
	public $rounding = 0;
	public $docType = '';

	function init ()
	{
		$this->addParam('vatPeriod');
		switch ($this->subReportId)
		{
			case '':
			case 'sum':
				$this->addParam('switch', 'rounding', array ('title' => 'Zaokrouhlení', 'switch' => array('0' => 'Ne', '1' => 'Ano')));
				break;
		}

		parent::init();

		$this->taxPeriod = $this->reportParams ['vatPeriod']['value'];
		if (isset ($this->reportParams ['rounding']['value']))
			$this->rounding = $this->reportParams ['rounding']['value'];
		$this->setInfo('icon', 'icon-shield');
		$this->setInfo('param', 'Období', $this->reportParams ['vatPeriod']['activeTitle']);
	}

	function createContent_Summary ()
	{
		$data = $this->createData_Summary();

		$h = array ('#' => '#', 'title' => 'Sazba', 'row' => ' Řádek DPH', 'base' => ' Základ', 'tax' => ' Daň', 'total' => ' Celkem');
		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE));

		$this->setInfo('title', 'Sumární přehled DPH');
		if ($this->rounding == 1)
			$this->setInfo('param', 'Přesnost', 'zaokrouhleno');
		$this->setInfo('saveFileName', 'Přehled DPH '.str_replace(' ', '', $this->reportParams ['vatPeriod']['activeTitle']).' - sumárně');
	}

	function createData_Summary ()
	{
		$q = '';
		if ($this->rounding == 1)
			$q .= "SELECT heads.taxPeriod, taxes.taxCode as taxCode, ROUND(SUM(taxes.sumBaseHc+taxes.sumTaxHc)) as sumTotal, ROUND(SUM(taxes.sumBaseHc)) as sumBase, ROUND(SUM(taxes.sumTaxHc)) as sumTax FROM e10doc_core_taxes as taxes";
		else
			$q .= "SELECT heads.taxPeriod, taxes.taxCode as taxCode, SUM(taxes.sumBaseHc+taxes.sumTaxHc) as sumTotal, SUM(taxes.sumBaseHc) as sumBase, SUM(taxes.sumTaxHc) as sumTax FROM e10doc_core_taxes as taxes";
		$q .= " LEFT JOIN e10doc_core_heads AS heads ON taxes.document = heads.ndx WHERE heads.taxPeriod = %i AND heads.docState = 4000";

		$cfgTaxCodes = $this->app()->cfgItem ('e10.base.taxCodes');
		$validTaxCodes = array ();
		foreach ($cfgTaxCodes as $key => $c)
			if ((isset ($c['rowTaxReturn'])) && ($c['rowTaxReturn'] != 0))
				$validTaxCodes[] = $key;
		if (count($validTaxCodes))
			$q .= " AND taxes.taxCode IN (" . implode (",", $validTaxCodes) . ")";

		$q .= " GROUP BY heads.taxPeriod, taxes.taxCode";

		$taxsums = $this->app->db()->query($q, $this->taxPeriod)->fetchAll ();

		$data = array ();

		$sumDirs [0] = array ('title' => 'Vstup', 'base' => 0.0, 'tax' => 0.0, 'total' => 0.0);
		$sumDirs [0]['_options']['class'] = 'subtotal';
		$sumDirs [0]['_options']['afterSeparator'] = 'separator';
		$sumDirs [1] = array ('title' => 'Výstup', 'base' => 0.0, 'tax' => 0.0, 'total' => 0.0);
		$sumDirs [1]['_options']['class'] = 'subtotal';
		$sumDirs [1]['_options']['afterSeparator'] = 'separator';

		$data [0] = array();
		$data [1] = array();

		forEach ($taxsums as $r)
		{
			$taxCode = $cfgTaxCodes [$r['taxCode']];
			$itemValue = array ('title' => $taxCode['fullName'], 'row' => $taxCode['rowTaxReturn'],
				'base' => $r['sumBase'], 'tax' => $r['sumTax'], 'total' => $r['sumTotal'],
				'taxCode' => $r['taxCode'], 'dir' => $taxCode['dir']
			);
			$data [$taxCode['dir']][] = $itemValue;
			$sumDirs [$taxCode['dir']]['base'] += $r['sumBase'];
			$sumDirs [$taxCode['dir']]['tax'] += $r['sumTax'];
			$sumDirs [$taxCode['dir']]['total'] += $r['sumTotal'];
		}

		// -- total
		$sumDirs ['total'] = array ('title' => 'Celkem',
			'base' => $sumDirs [1]['base'] - $sumDirs [0]['base'],
			'tax' => $sumDirs [1]['tax'] - $sumDirs [0]['tax'],
			'total' => $sumDirs [1]['total'] - $sumDirs [0]['total']);
		$sumDirs ['total']['_options']['class'] = 'sumtotal';


		// -- collect all lines
		$all = array();
		if (count($data['0']) != 0)
			$all = array_merge ($all, $data['0'], array(0 => $sumDirs [0]));
		if (count($data['1']) != 0)
			$all = array_merge ($all, $data['1'], array(0 => $sumDirs [1]));
		if (count($data['0']) != 0 && count($data['1']) != 0)
			$all = array_merge ($all, array(0 => $sumDirs ['total']));

		return $all;
	}

	function createContent_Items1 ()
	{
		$all = $this->createData_Items1();

		$h = array ('#' => '#', 'title' => 'Sazba', 'row' => 'Řádek DPH', 'document' => 'Doklad', 'dateTax' => 'DUZP', 'doctype' => 'Druh', 'base' => ' Základ', 'tax' => ' Daň', 'total' => ' Celkem');
		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $all, 'main' => TRUE));

		$this->setInfo('title', 'Položkový přehled DPH');
		$this->setInfo('saveFileName', 'Přehled DPH '.str_replace(' ', '', $this->reportParams ['vatPeriod']['activeTitle']).' - položkově 1');

	}

	function createData_Items1 ()
	{
		$q = "SELECT heads.taxPeriod, heads.docNumber, heads.ndx as headNdx, heads.docType, heads.dateTax as dateTax, taxes.taxCode as taxCode, SUM(taxes.sumBaseHc+taxes.sumTaxHc) as sumTotal, SUM(taxes.sumBaseHc) as sumBase, SUM(taxes.sumTaxHc) as sumTax FROM e10doc_core_taxes as taxes " .
			"LEFT JOIN e10doc_core_heads AS heads ON taxes.document = heads.ndx WHERE heads.taxPeriod = %i AND heads.docState = 4000 ";

		//if ($this->docType != '')
		//	$q .= " AND heads.docType = '{$this->docType}'";

		$cfgTaxCodes = Application::cfgItem ('e10.base.taxCodes');
		$validTaxCodes = array ();
		foreach ($cfgTaxCodes as $key => $c)
			if ((isset ($c['rowTaxReturn'])) && ($c['rowTaxReturn'] != 0))
				$validTaxCodes[] = $key;
		if (count($validTaxCodes))
			$q .= " AND taxes.taxCode IN (" . implode (",", $validTaxCodes) . ")";

		$q .= " GROUP BY heads.taxPeriod, heads.docType, heads.docNumber, taxes.taxCode";

		$taxsums = $this->app->db()->query($q, $this->taxPeriod)->fetchAll ();

		$data = array ();

		$sumDirs [0] = array ('title' => 'Vstup', 'base' => 0.0, 'tax' => 0.0, 'total' => 0.0);
		$sumDirs [0]['_options']['class'] = 'subtotal';
		$sumDirs [0]['_options']['afterSeparator'] = 'separator';
		$sumDirs [1] = array ('title' => 'Výstup', 'base' => 0.0, 'tax' => 0.0, 'total' => 0.0);
		$sumDirs [1]['_options']['class'] = 'subtotal';
		$sumDirs [1]['_options']['afterSeparator'] = 'separator';

		$data [0] = array();
		$data [1] = array();

		forEach ($taxsums as $r)
		{
			$docType = Application::cfgItem ('e10.docs.types.' . $r['docType']);
			$taxCode = $cfgTaxCodes [$r['taxCode']];
			$data [$taxCode['dir']][] = array ('title' => $taxCode['fullName'], 'row' => $taxCode['rowTaxReturn'],
				'document' => array ('text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['headNdx']),
				'doctype' => $docType['shortName'],
				'base' => $r['sumBase'], 'tax' => $r['sumTax'], 'total' => $r['sumTotal'], 'dateTax' => $r['dateTax']
			);
			$sumDirs [$taxCode['dir']]['base'] += $r['sumBase'];
			$sumDirs [$taxCode['dir']]['tax'] += $r['sumTax'];
			$sumDirs [$taxCode['dir']]['total'] += $r['sumTotal'];
		}

		// -- total
		$sumDirs ['total'] = array ('title' => 'Celkem',
			'base' => $sumDirs [1]['base'] - $sumDirs [0]['base'],
			'tax' => $sumDirs [1]['tax'] - $sumDirs [0]['tax'],
			'total' => $sumDirs [1]['total'] - $sumDirs [0]['total']);
		$sumDirs ['total']['_options']['class'] = 'sumtotal';

		// -- collect all lines
		$all = array_merge ($data['0'], array(0 => $sumDirs [0]), $data['1'], array(0 => $sumDirs [1]), array(0 => $sumDirs ['total']));

		return $all;
	} // createContent_Items1

	function createContent_Items2 ()
	{
		$all = $this->createData_Items2();

		$h = array ('#' => '#', 'title' => 'Kód', 'row' => 'Ř.DPH',
			'document' => 'Doklad', 'dateTax' => 'DUZP', 'shortDocType' => 'Druh',
			'base' => ' Základ', 'tax' => ' Daň', 'total' => ' Celkem',
			'pvin' => 'DIČ', 'pn' => 'Partner');
		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $all, 'main' => TRUE));

		$this->setInfo('title', 'Položkový přehled DPH');
		$this->setInfo('saveFileName', 'Přehled DPH '.str_replace(' ', '', $this->reportParams ['vatPeriod']['activeTitle']).' - položkově 2');
	}

	function createData_Items2 ()
	{
		$q[] = 'SELECT heads.taxPeriod, heads.docNumber, heads.docType, heads.dateTax as dateTax, taxes.taxCode as taxCode,';
		array_push ($q, ' persons.fullName as personName, heads.personVATIN as personVATIN, heads.ndx as headNdx,');
		array_push ($q, ' SUM(taxes.sumBaseHc+taxes.sumTaxHc) as sumTotal, SUM(taxes.sumBaseHc) as sumBase, SUM(taxes.sumTaxHC) as sumTax');
		array_push ($q, ' FROM e10doc_core_taxes as taxes');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON taxes.document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.taxPeriod = %i', $this->taxPeriod, ' AND heads.docState = 4000 ');

		$cfgTaxCodes = $this->app()->cfgItem ('e10.base.taxCodes');
		$validTaxCodes = array ();
		foreach ($cfgTaxCodes as $key => $c)
			if ((isset ($c['rowTaxReturn'])) && ($c['rowTaxReturn'] != 0))
				$validTaxCodes[] = $key;
		if (count($validTaxCodes))
			array_push ($q, ' AND taxes.taxCode IN %in', $validTaxCodes);

		array_push ($q, ' GROUP BY taxes.taxCode, heads.docType, heads.docNumber');

		$taxsums = $this->app->db()->query($q);

		$data = array ();
		$sumCodes = array ();

		$sumDirs [0] = array ('title' => 'Vstup', 'base' => 0.0, 'tax' => 0.0, 'total' => 0.0);
		$sumDirs [0]['_options']['class'] = 'subtotal';
		$sumDirs [0]['_options']['afterSeparator'] = 'separator';
		$sumDirs [0]['_options']['colSpan'] = array ('title' => 5, 'pvin' => 2);
		$sumDirs [1] = array ('title' => 'Výstup', 'base' => 0.0, 'tax' => 0.0, 'total' => 0.0);
		$sumDirs [1]['_options']['class'] = 'subtotal';
		$sumDirs [1]['_options']['afterSeparator'] = 'separator';
		$sumDirs [1]['_options']['colSpan'] = array ('title' => 5, 'pvin' => 2);

		$data [0] = array();
		$data [1] = array();

		forEach ($taxsums as $r)
		{
			$docType = $this->app()->cfgItem ('e10.docs.types.' . $r['docType']);
			$taxCode = $cfgTaxCodes [$r['taxCode']];
			$data [$taxCode['dir']][$r['taxCode']][] =
				array ('title' => $r['taxCode'], 'row' => $taxCode['rowTaxReturn'],
					'document' => array ('text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['headNdx']),
					'docType' => $r['docType'], 'shortDocType' => $docType['shortcut'], 'taxCode' => $r['taxCode'], 'dir' => $taxCode['dir'],
					'base' => $r['sumBase'], 'tax' => $r['sumTax'], 'total' => $r['sumTotal'], 'dateTax' => $r['dateTax'],
					'pvin' => $r['personVATIN'], 'pn' => $r['personName']);
			$sumDirs [$taxCode['dir']]['base'] += $r['sumBase'];
			$sumDirs [$taxCode['dir']]['tax'] += $r['sumTax'];
			$sumDirs [$taxCode['dir']]['total'] += $r['sumTotal'];

			if (!isset($sumCodes [$taxCode['dir']][$r['taxCode']]))
				$sumCodes [$taxCode['dir']][$r['taxCode']] = array ('title' => $taxCode['fullName'], 'base' => 0.0, 'tax' => 0.0, 'total' => 0.0);

			$sumCodes [$taxCode['dir']][$r['taxCode']]['base'] += $r['sumBase'];
			$sumCodes [$taxCode['dir']][$r['taxCode']]['tax'] += $r['sumTax'];
			$sumCodes [$taxCode['dir']][$r['taxCode']]['total'] += $r['sumTotal'];
		}

		// -- total
		$sumDirs ['total'] = array ('title' => 'Celkem',
			'base' => $sumDirs [1]['base'] - $sumDirs [0]['base'],
			'tax' => $sumDirs [1]['tax'] - $sumDirs [0]['tax'],
			'total' => $sumDirs [1]['total'] - $sumDirs [0]['total']);
		$sumDirs ['total']['_options']['class'] = 'sumtotal';
		$sumDirs ['total']['_options']['colSpan'] = array ('title' => 5, 'pvin' => 2);


		// -- collect all lines
		//$all = array_merge ($data['0'], array(0 => $sumDirs [0]), $data['1'], array(0 => $sumDirs [1]), array(0 => $sumDirs ['total']));
		$all = array ();
		forEach ($data as $dirId => $dataDirs)
		{
			forEach ($dataDirs as $codeId => $dataCodes)
			{
				$sumCodes[$dirId][$codeId]['_options']['class'] = 'subtotal';
				$sumCodes[$dirId][$codeId]['_options']['afterSeparator'] = 'separator';
				$sumCodes[$dirId][$codeId]['_options']['colSpan'] = array ('title' => 5, 'pvin' => 2);
				$all = array_merge ($all, $data[$dirId][$codeId], array(0 => $sumCodes[$dirId][$codeId]));
			}
			$all = array_merge ($all, array(0 => $sumDirs [$dirId]));
		}
		$all = array_merge ($all, array(0 => $sumDirs ['total']));

		return $all;
	} // createContent_Items2

	function createContent_RevCharge ()
	{
		$all = $this->createData_RevCharge();
		$data = $all['data'];
		$sumDirs = $all['sumDirs'];

		$h = array ('#' => '#', 'pvin' => 'DIČ', 'code' => 'Kód', 'dateTax' => 'DUZP',
			'base' => ' Základ daně', 'amount' => ' Rozsah plnění', 'unit' => 'Jednotka');

		// -- collect all lines
		$all = array ();
		forEach ($data as $dirId => $dataDirs)
		{
			if (count ($dataDirs) == 0)
				continue;

			if ($dirId === 0)
				$title = 'Vstup (odběratel)';
			else
				$title = 'Výstup (dodavatel)';

			forEach ($dataDirs as $codeId => $dataCodes)
				$all = array_merge ($all, $data[$dirId][$codeId]);
			$all = array_merge ($all, array(0 => $sumDirs [$dirId]));

			$this->addContent (array ('type' => 'table', 'title' => $title,
				'header' => $h, 'table' => $all, 'main' => TRUE));
			$all = array ();
		}

		$this->setInfo('title', 'Přehled DPH - přenesení daňové povinnosti');
		$this->setInfo('saveFileName', 'Přehled DPH '.str_replace(' ', '', $this->reportParams ['vatPeriod']['activeTitle']).' - PDP');
	}

	function createData_RevCharge ()
	{
		$q = "SELECT heads.taxPeriod, heads.docType as docType, heads.docNumber as docNumber, heads.dateTax as dateTax,
								taxes.taxCode as taxCode, heads.personVATIN as personVATIN, heads.ndx as headNdx,
								SUM(taxes.sumBaseHc) as sumBase, SUM(taxes.weight) as weight, SUM(taxes.quantity) as quantity
					FROM e10doc_core_taxes as taxes
				  LEFT JOIN e10doc_core_heads AS heads ON taxes.document = heads.ndx
					WHERE heads.taxPeriod = %i AND heads.docState = 4000 ";

		$cfgTaxCodes = $this->app()->cfgItem ('e10.base.taxCodes');
		$validTaxCodes = array ();

		foreach ($cfgTaxCodes as $key => $c)
			if ((isset ($c['reverseCharge'])) && ($c['reverseCharge'] === 1))
				$validTaxCodes[] = $key;

		if (count($validTaxCodes))
			$q .= " AND taxes.taxCode IN (" . implode (",", $validTaxCodes) . ")";

		$q .= " GROUP BY taxes.taxCode, heads.docType, heads.docNumber";

		$taxsums = $this->app->db()->query($q, $this->taxPeriod);

		$data = array ();

		$sumDirs [0] = array ('pvin' => 'Vstup', 'base' => 0.0, 'tax' => 0.0, 'total' => 0.0);
		$sumDirs [0]['_options']['class'] = 'sumtotal';
		$sumDirs [0]['_options']['colSpan'] = array ('pvin' => 3);
		$sumDirs [1] = array ('pvin' => 'Výstup', 'base' => 0.0, 'tax' => 0.0, 'total' => 0.0);
		$sumDirs [1]['_options']['class'] = 'sumtotal';
		$sumDirs [1]['_options']['colSpan'] = array ('pvin' => 3);

		$data [0] = array();
		$data [1] = array();

		forEach ($taxsums as $r)
		{
			$taxCode = $cfgTaxCodes [$r['taxCode']];
			$addRow = array ('code' => $taxCode['reverseChargeCode'], 'base' => intval (utils::round($r['sumBase'], 0, 0)), 'dateTax' => $r['dateTax'],
				'docNumber' => $r['docNumber'], 'docType' => $r['docType'], 'pvin' => $r['personVATIN']);

			if (isset ($taxCode['reverseChargeAmount']))
			{
				switch ($taxCode['reverseChargeAmount'])
				{
					case 'w': $addRow['amount'] = intval (utils::round($r['weight'], 0, 0)); $addRow['unit'] = 'kg'; break;
					case 'q': $addRow['amount'] = intval (utils::round($r['quantity'], 0, 0)); $addRow['unit'] = 'ks'; break;
				}
			}
			$data [$taxCode['dir']][$r['taxCode']][] = $addRow;
			$sumDirs [$taxCode['dir']]['base'] += intval (utils::round($r['sumBase'], 0, 0));
			$sumDirs [$taxCode['dir']]['base'] = intval (utils::round($sumDirs [$taxCode['dir']]['base'], 0, 0));
		}

		$all = array();
		$all['data'] = $data;
		$all['sumDirs'] = $sumDirs;
		return $all;
	} // createContent_RevCharge

	function createContent_IntraCommunity ()
	{
		$data = $this->createData_IntraCommunity();

		$h = array ('#' => '#', 'country' => 'Kód země', 'pvin' => 'DIČ', 'code' => 'Kód plnění', 'cnt' => '+Počet plnění',
			'amount' => '+Celková hodnota plnění v Kč');

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE));
		$this->setInfo('title', 'Souhrnné hlášení DPH');
		$this->setInfo('saveFileName', 'Přehled DPH '.str_replace(' ', '', $this->reportParams ['vatPeriod']['activeTitle']).' - souhrnné hlášení');
	}

	function createData_IntraCommunity ()
	{
		$q = "SELECT heads.taxPeriod, taxes.taxCode as taxCode,
								 heads.personVATIN as personVATIN,
								 SUM(taxes.sumBaseHc) as sumBase, COUNT(*) as cntRows
					FROM e10doc_core_taxes as taxes
				  LEFT JOIN e10doc_core_heads AS heads ON taxes.document = heads.ndx
					WHERE heads.taxPeriod = %i AND heads.docState = 4000 ";

		$cfgTaxCodes = $this->app()->cfgItem ('e10.base.taxCodes');
		$validTaxCodes = array ();

		foreach ($cfgTaxCodes as $key => $c)
			if (isset ($c['intraCommunityCode']))
				$validTaxCodes[] = $key;

		if (count($validTaxCodes))
			$q .= " AND taxes.taxCode IN (" . implode (",", $validTaxCodes) . ")";

		$q .= " GROUP BY taxes.taxCode, heads.personVATIN";

		$rows = $this->app->db()->query($q, $this->taxPeriod);

		$data = array ();
		forEach ($rows as $r)
		{
			$taxCode = $cfgTaxCodes [$r['taxCode']];
			$addRow = array ('country' => substr ($r['personVATIN'], 0, 2),
											 'code' => $taxCode['intraCommunityCode'], 'cnt' => $r['cntRows'],
											 'amount' => intval (utils::round($r['sumBase'], 0, 1)), 'pvin' => $r['personVATIN']);

			$data [] = $addRow;
		}

		return $data;
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Summary (); break;
			case 'items1': $this->createContent_Items1 (); break;
			case 'items2': $this->createContent_Items2 (); break;
			case 'revCharge': $this->createContent_RevCharge (); break;
			case 'intraCommunity': $this->createContent_IntraCommunity (); break;
		}
	}

	public function subReportsList ()
	{
		$d[] = array ('id' => 'sum', 'icon' => 'detailReportSum', 'title' => 'Sumárně');
		$d[] = array ('id' => 'items1', 'icon' => 'icon-list', 'title' => 'Položkově 1');
		$d[] = array ('id' => 'items2', 'icon' => 'icon-list-ul', 'title' => 'Položkově 2');
		$d[] = array ('id' => 'revCharge', 'icon' => 'icon-share', 'title' => 'PDP');
		$d[] = array ('id' => 'intraCommunity', 'icon' => 'icon-star', 'title' => 'Souhrnné hlášení');

		return $d;
	}
} // class reportVATSummary


/**
 * reportCashBook
 *
 */

class reportCashBook extends \e10doc\core\libs\reports\GlobalReport
{
	function init ()
	{
		$periodFlags = array('quarters', 'halfs', 'years');
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', array('flags' => $periodFlags));
		$this->addParam ('cashBox');
		parent::init();
	}

	function createContent ()
	{
		$fmNdx = $this->reportParams ['fiscalPeriod']['value'];
		$fmBeginDate = $this->reportParams ['fiscalPeriod']['values'][$fmNdx]['dateBegin'];
		$fmFiscalYear = $this->reportParams ['fiscalPeriod']['values'][$fmNdx]['fiscalYear'];

		$q [] = "SELECT heads.[ndx] as ndx, [docNumber], [title], heads.[docType] as [docType], [toPay], [person], [currency], [homeCurrency], [dateAccounting], [totalCash],
							heads.initState as initState, persons.fullName as personFullName
							FROM e10_persons_persons AS persons RIGHT JOIN [e10doc_core_heads] as heads ON (heads.person = persons.ndx) WHERE 1";

		E10Utils::fiscalPeriodQuery ($q, $fmNdx);

		array_push ($q, " AND heads.cashBox = %i", $this->reportParams ['cashBox']['value']);
		array_push ($q, " AND heads.docType IN ('invni', 'invno', 'cash', 'cashreg', 'purchase')");
		array_push ($q, " AND heads.totalCash != 0 AND heads.docState = 4000");
		array_push ($q, " ORDER BY heads.[dateAccounting], heads.[docNumber]");

		$data = array ();
		$rows = $this->app->db()->query($q);

		$bilance = E10Utils::getCashBoxInitState ($this->app, $this->reportParams ['cashBox']['value'], $fmBeginDate, $fmFiscalYear);

		$data [] = array (
			'docNumber' => 'Počáteční zůstatek', 'bilance' => $bilance, '_options' => array ('class' => 'subtotal', 'colSpan' => array ('docNumber' => 7))
		);

		forEach ($rows as $r)
		{
			$docType = $this->app()->cfgItem ('e10.docs.types.' . $r['docType']);
			$bilance += $r['totalCash'];
			$newItem = array (
				'docNumber' => array ('text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['ndx']),
				'doctype' => $docType['shortcut'], 'title' => $r['title'],
				'dateAccounting' => $r['dateAccounting'], 'person' => $r['personFullName'],
				'bilance' => $bilance,
			);

			if ($r['totalCash'] < 0)
				$newItem ['cashOut'] = $r['totalCash'] * -1;
			else
				$newItem ['cashIn'] = $r['totalCash'];
			$data [] = $newItem;
		}
		$data [] = array (
			'docNumber' => 'Konečný zůstatek', 'bilance' => $bilance, '_options' => array ('class' => 'subtotal', 'colSpan' => array ('docNumber' => 7))
		);

		$h = array ('#' => '#', 'docNumber' => 'Doklad', 'doctype' => 'DD',
								'dateAccounting' => 'Účetní datum', 'person' => 'Partner', 'title' => 'Poznámka',
								'cashIn' => '+Přijato', 'cashOut' => '+Vyplaceno', 'bilance' => ' Zůstatek');

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE));

		$this->setInfo('title', 'Pokladní kniha');
		$this->setInfo('icon', 'report/cashBook');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('param', 'Pokladna', $this->reportParams ['cashBox']['activeTitle']);
		$cashBoxes = $this->app->cfgItem ('e10doc.cashBoxes', array());
		$cashBoxCurrency = $cashBoxes[$this->reportParams ['cashBox']['value']]['curr'];
		$currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->setInfo('note', '1', 'Všechny částky jsou v '.$currencies[$cashBoxCurrency]['shortcut']);
		$this->setInfo('saveFileName', 'Pokladní kniha '.str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']).' - '.$this->reportParams ['cashBox']['activeTitle']);
	}
} // class reportCashBook



