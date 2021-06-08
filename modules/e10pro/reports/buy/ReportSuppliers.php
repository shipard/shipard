<?php

namespace e10Pro\reports\buy;

use e10doc\core\libs\E10Utils, \e10\utils, \e10\uiutils;


/**
 * Class ReportSuppliers
 * @package e10Pro\reports\buy
 */
class ReportSuppliers extends \e10doc\core\libs\reports\GlobalReport
{
	var $units;
	var $currencies;
	var $fpq = FALSE;
	var $data = [];
	var $usedFiscalYears = [];

	var $cntFiscalYears = 5;
	var $enabledFiscalYears = [];

	function init ()
	{
		$this->addParamTopOrder();

		if ($this->subReportId === '' || $this->subReportId === 'all')
			$this->addParam ('fiscalPeriod', 'fiscalPeriod', array('flags' => ['enableAll', 'quarters', 'halfs', 'years']));

		$this->addParam ('hidden', 'mainTabs');

		parent::init();

		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$this->setInfo('note', '1', 'Všechny částky jsou bez DPH');

		$this->fpq = (!isset($this->reportParams ['fiscalPeriod']) || $this->reportParams ['fiscalPeriod']['value'] == '0') ? 0 : 1;

		if ($this->fpq)
			$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		else
			if (!in_array($this->reportParams ['amountOrderPeriod']['value'], ['all', 'abc']))
			$this->setInfo('param', 'Období', $this->reportParams ['amountOrderPeriod']['activeTitle']);
	}

	function addParamTopOrder ()
	{
		$fyParamValue = uiutils::detectParamValue('fiscalPeriod', '0');

		$fiscalYears = \e10\sortByOneKey($this->app->cfgItem('e10doc.acc.periods'), 'begin', TRUE, FALSE);
		$today = utils::today('Y-m-d');
		$enum = [];

		$paramTitle = 'Období';

		if ($this->subReportId === '' || $this->subReportId === 'all')
		{
			$enum['abc'] = 'abc';
			$enum['all'] = 'celkem';
			$paramTitle = 'Seřadit';
		}
		if ($fyParamValue == '0')
		{
			$cnt = 0;
			foreach ($fiscalYears as $fyNdx => $fy)
			{
				if ($today < $fy['begin'])
					continue;
				$this->enabledFiscalYears[] = $fy['ndx'];
				$enum[$fy['ndx']] = $fy['fullName'];
				$cnt++;
				if ($cnt >= $this->cntFiscalYears)
					break;
			}
		}

		$this->addParam ('switch', 'amountOrderPeriod', ['title' => $paramTitle, 'switch' => $enum, 'radioBtn' => 1]);
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'all': $this->createContent_All (); break;
			case 'top': $this->createContent_Top (); break;
			case 'breakthroughs': $this->createContent_Breakthroughs (); break;
			case 'renegades': $this->createContent_Renegades (); break;
		}
	}

	function createCoreData ()
	{
		$q[] = 'SELECT persons.ndx as personNdx, persons.id as personId, persons.fullName as personName, heads.homeCurrency as currency,';

		array_push ($q, 'SUM([rows].taxBaseHc) AS price');

		if (!$this->fpq)
			array_push ($q, ', heads.fiscalYear AS fiscalYear');

		array_push ($q, ' FROM e10doc_core_rows as [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.docState = 4000 ');

		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		if (!$this->fpq)
			array_push ($q, ' AND [heads].[fiscalYear] IN %in', $this->enabledFiscalYears);

		array_push ($q, ' AND docType IN %in', ['invni']);

		array_push ($q, ' GROUP BY heads.person, heads.homeCurrency');
		if (!$this->fpq)
			array_push ($q, ', heads.fiscalYear');

		array_push ($q, ' ORDER BY personName');


		$rows = $this->app->db()->query($q);
		forEach ($rows as $r)
		{
			$pNdx = $r ['personNdx'];
			$fyId = 'FY'.$r ['fiscalYear'];

			if (!isset ($this->data[$pNdx]))
			{
				$newItem = [
						'price' => $r['price'], $fyId.'-price' => $r['price'],
						'personName' => $r['personName']];

				$newItem ['currency'] = $this->currencies[$r['currency']]['shortcut'];

				if ($r ['personNdx'] != 0)
					$newItem ['custId'] = ['text'=> $r ['personId'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r ['personNdx']];
				else
					$newItem ['personName'] = 'Drobný prodej';

				$this->data[$pNdx] = $newItem;
			}
			else
			{
				$this->data[$pNdx]['price'] += $r['price'];
				if (isset($this->data[$pNdx][$fyId.'-price']))
					$this->data[$pNdx][$fyId.'-price'] += $r['price'];
				else
					$this->data[$pNdx][$fyId.'-price'] = $r['price'];
			}

			if (!isset($this->usedFiscalYears[$fyId]))
			{
				$fyCfg = $this->app->cfgItem ('e10doc.acc.periods.'.$r['fiscalYear']);
				$this->usedFiscalYears[$fyId] = [
						'cfg' => $fyCfg, 'order' => $fyCfg['begin'],
				];
			}
		}

		$this->calcData();
		return $this->data;
	}

	function calcData()
	{
		$this->usedFiscalYears = \E10\sortByOneKey($this->usedFiscalYears, 'order', TRUE, FALSE);

		$lastFYId = FALSE;
		foreach ($this->usedFiscalYears as $fyId => $fy)
		{
			if ($lastFYId !== FALSE)
				$this->usedFiscalYears[$lastFYId]['prev'] = $fyId;
			$lastFYId = $fyId;
		}

		foreach ($this->usedFiscalYears as $fyId => $fy)
		{
			if (!isset ($fy['prev']))
				continue;

			$thisPriceColId = $fyId.'-price';
			$prevPriceColId = $fy['prev'].'-price';
			$diffPriceColId = $fyId.'-diff';

			foreach ($this->data as $personNdx => $item)
			{
				$thisAmount = (isset($this->data [$personNdx][$thisPriceColId])) ? $this->data [$personNdx][$thisPriceColId] : 0.0;
				$prevAmount = (isset($this->data [$personNdx][$prevPriceColId])) ? $this->data [$personNdx][$prevPriceColId] : 0.0;
				$this->data [$personNdx][$diffPriceColId] = $thisAmount - $prevAmount;

				if ($thisAmount)
					$this->data [$personNdx][$diffPriceColId.'Perc'] = round(((1 - $prevAmount / $thisAmount) * 100.0), 1);
				else
					$this->data [$personNdx][$diffPriceColId.'Perc'] = 0;
			}
		}
	}

	function createContent_All ()
	{
		$this->createCoreData();
		$colClasses = [];

		if ($this->reportParams ['amountOrderPeriod']['value'] === 'abc')
		{
			$data = $this->data;
		}
		elseif ($this->reportParams ['amountOrderPeriod']['value'] === 'all')
		{
			$data = \E10\sortByOneKey($this->data, 'price', TRUE, FALSE);
			$colClasses['price'] = 'e10-row-this';
		}
		else
		{
			$data = \E10\sortByOneKey($this->data, 'FY' . $this->reportParams ['amountOrderPeriod']['value'] . '-price', TRUE, FALSE);
			$colClasses['FY' . $this->reportParams ['amountOrderPeriod']['value'] . '-price'] = 'e10-row-this';
		}

		$h = ['#' => '#', 'custId' => ' id', 'personName' => 'Dodavatel', 'price' => '+Celkem', 'currency' => 'Měna'];

		if (!$this->fpq)
		{
			foreach ($this->usedFiscalYears as $fyId => $fyCfg)
			{
				$h[$fyId . '-price'] = '+' . $fyCfg['cfg']['fullName'];
			}
		}

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE, 'params' => ['colClasses' => $colClasses, 'precision' => 0]]);

		$this->setInfo('icon', 'e10doc-buy/suppliers');
		$this->setInfo('title', 'Dodavatelé');
		$this->paperOrientation = 'landscape';
	}

	function createContent_Breakthroughs()
	{
		$this->createCoreData();
		$colClasses = [];

		$h = ['#' => '#', 'custId' => ' id', 'personName' => 'Dodavatel', 'price' => '+Celkem', /*'currency' => 'Měna'*/];

		$sortColumnBase = 'FY'.$this->reportParams ['amountOrderPeriod']['value'];
		$sortColumnId = $sortColumnBase.'-diff';

		$data = \E10\sortByOneKey($this->data, $sortColumnId, TRUE, FALSE);
		$h ['FY'.$this->reportParams ['amountOrderPeriod']['value'] . '-diff'] = '+' . 'Nárust';
		$colClasses[$sortColumnBase.'-price'] = 'e10-row-this';
		$colClasses[$sortColumnBase.'-diff'] = 'e10-row-this';

		foreach ($this->usedFiscalYears as $fyId => $fyCfg)
		{
			$h[$fyId . '-price'] = '+' . $fyCfg['cfg']['fullName'];
		}

		$allData = [];
		foreach ($data as $personNdx => $item)
		{
			if ($item[$sortColumnId] <= 0.0)
				continue;
			$allData[$personNdx] = $item;
		}

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $allData, 'main' => TRUE, 'params' => ['colClasses' => $colClasses, 'precision' => 0]]);

		$this->setInfo('icon', 'icon-level-up');
		$this->setInfo('title', 'Skokani');
		$this->paperOrientation = 'landscape';
	}

	function createContent_Renegades()
	{
		$this->createCoreData();
		$colClasses = [];

		$h = ['#' => '#', 'custId' => ' id', 'personName' => 'Dodavatel', 'price' => '+Celkem', /*'currency' => 'Měna'*/];

		$sortColumnBase = 'FY'.$this->reportParams ['amountOrderPeriod']['value'];
		$sortColumnId = $sortColumnBase.'-diff';

		$data = \E10\sortByOneKey($this->data, $sortColumnId, TRUE, TRUE);
		$h ['FY'.$this->reportParams ['amountOrderPeriod']['value'] . '-diff'] = '+' . 'Úbytek';
		$colClasses[$sortColumnBase.'-price'] = 'e10-row-this';
		$colClasses[$sortColumnBase.'-diff'] = 'e10-row-this';

		foreach ($this->usedFiscalYears as $fyId => $fyCfg)
		{
			$h[$fyId . '-price'] = '+' . $fyCfg['cfg']['fullName'];
		}

		$allData = [];
		foreach ($data as $personNdx => $item)
		{
			if ($item[$sortColumnId] >= 0.0)
				continue;
			$allData[$personNdx] = $item;
		}

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $allData, 'main' => TRUE, 'params' => ['colClasses' => $colClasses, 'precision' => 0]]);

		$this->setInfo('icon', 'icon-level-up');
		$this->setInfo('title', 'Odpadlíci');
		$this->paperOrientation = 'landscape';
	}


	function createContent_Top ()
	{
		$this->createCoreData();

		$periodTitle = '';

		$priceColId = 'price';
		if ($this->reportParams ['amountOrderPeriod']['value'] === 'abc')
		{
			$data = \E10\sortByOneKey($this->data, 'price', TRUE, FALSE);
		}
		elseif ($this->reportParams ['amountOrderPeriod']['value'] === 'all')
		{
			$data = \E10\sortByOneKey($this->data, 'price', TRUE, FALSE);
		}
		else
		{
			$priceColId = 'FY' . $this->reportParams ['amountOrderPeriod']['value'] . '-price';
			$data = \E10\sortByOneKey($this->data, $priceColId, TRUE, FALSE);
			$periodTitle = ' '.$this->reportParams ['amountOrderPeriod']['activeTitle'];
		}

		$maxRows = 15;
		$cutedData = [];
		$cutedSum = [];

		utils::cutRows ($data, $cutedData, [$priceColId], $cutedSum, $maxRows);
		if (count($cutedSum))
		{
			$cutedSum['personName'] = 'Ostatní';
			$cutedData[] = $cutedSum;
		}

		$h = ['#' => '#', 'custId' => ' id', 'personName' => 'Dodavatel', $priceColId => '+Celkem'.$periodTitle, 'currency' => 'Měna'];


		$pieData = [];
		foreach ($cutedData as $r)
			$pieData[] = [$r['personName'], $r[$priceColId]];

		$this->addContent(['tabsId' => 'mainTabs', 'selectedTab' => $this->reportParams ['mainTabs']['value'], 'tabs' => [
				['title' => ['icon' => 'icon-table', 'text' => 'Tabulka'], 'content' => [['type' => 'table', 'header' => $h, 'table' => $cutedData]]],
				['title' => ['icon' => 'icon-pie-chart', 'text' => 'Podíly'], 'content' => [['type' => 'graph',
						'graphType' => 'pie', 'graphData' => $pieData]]],
		]]);

		$this->setInfo('title', 'Největší dodavatelé'.$periodTitle);
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'all', 'icon' => 'system/detailDetail', 'title' => 'Přehled'];
		$d[] = ['id' => 'top', 'icon' => 'detailReportTop', 'title' => 'TOP'];
		$d[] = ['id' => 'breakthroughs', 'icon' => 'detailReportBreakthroughs', 'title' => 'Skokani'];
		$d[] = ['id' => 'renegades', 'icon' => 'detailReportRenegades', 'title' => 'Odpadlíci'];


		return $d;
	}
}
