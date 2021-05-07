<?php

namespace E10Pro\Reports\Sale_Customers;

use E10Doc\Core\e10utils, \E10\uiutils, \E10\utils;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';


/**
 * Class reportCustomers
 * @package E10Pro\Reports\Sale_Customers
 */
class reportCustomers extends \E10Doc\Core\GlobalReport
{
	var $units;
	var $currencies;
	var $useQuantity = 0;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', array('flags' => ['enableAll', 'quarters', 'halfs', 'years']));
		$this->addParam ('switch', 'itemKind', ['title' => 'Druh', 'switch' => ['999' => 'Vše', '0' => 'Služba', '1' => 'Zásoba']]);
		$this->addParamItemsTypes ();
		$this->addParamItemsBrands ();
		$this->addParam ('switch', 'useQuantity', ['title' => 'Množství', 'switch' => ['0' => 'Ne', '1' => 'Ano']]);
		$this->addParam ('hidden', 'mainTabs');

		parent::init();

		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$this->setInfo('icon', 'icon-thumbs-o-up');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('note', '1', 'Všechny částky jsou bez DPH');

		$this->useQuantity = intval ($this->reportParams ['useQuantity']['value']);
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'abc': $this->createContent_Abc (); break;
			case 'amount': $this->createContent_Amount (); break;
			case 'top': $this->createContent_Top (); break;
		}
	}

	function createContent_Abc ()
	{
		$data = $this->createCoreData(FALSE);

		if ($this->useQuantity)
			$h = ['custId' => ' id', 'personName' => 'Zákazník', 'quantity' => ' Množství', 'rowUnit' => 'jed.',
						'price' => '+Obrat celkem', 'currency' => 'Měna'];
		else
			$h = ['custId' => ' id', 'personName' => 'Zákazník', 'price' => '+Obrat', 'currency' => 'Měna'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Obraty odběratelů');
	}

	function createCoreData ($top)
	{
		$q[] = 'SELECT persons.ndx as personNdx, persons.id as personId, persons.fullName as personName, heads.homeCurrency as currency,';

		if ($this->useQuantity)
			array_push ($q, '[rows].unit as rowUnit, SUM([rows].quantity) as quantity, ');

		array_push ($q, 'SUM([rows].taxBaseHc) as price');

		array_push ($q, ' FROM e10doc_core_rows as [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.docState = 4000 ');

		e10utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		if ($this->reportParams ['itemKind']['value'] != 999)
		{
			array_push ($q, ' AND items.itemKind = %i', $this->reportParams ['itemKind']['value']);
			$this->setInfo('param', 'Druh', $this->reportParams ['itemKind']['activeTitle']);
		}

		if ($this->reportParams ['itemType']['value'] != -1)
		{
			array_push ($q, ' AND items.itemType = %i', $this->reportParams ['itemType']['value']);
			$this->setInfo('param', 'Typ', $this->reportParams ['itemType']['activeTitle']);
		}

		if ($this->reportParams ['itemBrand']['value'] != -1)
		{
			array_push ($q, ' AND items.brand = %i', $this->reportParams ['itemBrand']['value']);
			$this->setInfo('param', 'Značka', $this->reportParams ['itemBrand']['activeTitle']);
		}

		array_push ($q, ' AND docType IN %in', ['invno', 'cashreg']);

		if ($this->useQuantity)
		{
			array_push ($q, ' GROUP BY heads.person, [rows].unit, heads.homeCurrency');
			if ($top)
				array_push ($q, ' ORDER BY personName, rowUnit');
			else
				array_push ($q, ' ORDER BY price DESC, personName, rowUnit');
		}
		else
		{
			array_push ($q, ' GROUP BY heads.person, heads.homeCurrency');
			if ($top)
				array_push ($q, ' ORDER BY price DESC');
			else
				array_push ($q, ' ORDER BY personName');
		}
		$rows = $this->app->db()->query($q);

		$data = [];

		forEach ($rows as $r)
		{
			$newItem = $r->toArray();
			if ($this->useQuantity)
				$newItem ['rowUnit'] = $this->units[$r['rowUnit']]['shortcut'];
			$newItem ['currency'] = $this->currencies[$r['currency']]['shortcut'];
			if ($r ['personNdx'] != 0)
				$newItem ['custId'] = ['text'=> $r ['personId'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r ['personNdx']];
			else
				$newItem ['personName'] = 'Drobný prodej';

			$data[] = $newItem;
		}

		return $data;
	}

	function createContent_Amount ()
	{
		$data = $this->createCoreData(TRUE);

		if ($this->useQuantity)
			$h = ['#' => '#', 'custId' => ' id', 'personName' => 'Zákazník', 'quantity' => ' Množství', 'rowUnit' => 'jed.',
						'price' => '+Obrat celkem', 'currency' => 'Měna'];
		else
			$h = ['#' => '#', 'custId' => ' id', 'personName' => 'Zákazník', 'price' => '+Obrat', 'currency' => 'Měna'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Největší odběratelé');
	}

	function createContent_Top ()
	{
		$data = $this->createCoreData(TRUE);

		$maxRows = 10;
		$cutedData = [];
		$cutedSum = [];

		utils::cutRows ($data, $cutedData, ['price'], $cutedSum, $maxRows);
		if (count($cutedSum))
		{
			$cutedSum['personName'] = 'Ostatní';
			$cutedData[] = $cutedSum;
		}
		if ($this->useQuantity)
			$h = ['custId' => ' id', 'personName' => 'Zákazník', 'quantity' => ' Množství', 'rowUnit' => 'jed.',
						'price' => '+Obrat celkem', 'currency' => 'Měna'];
		else
			$h = ['#' => '#', 'custId' => ' id', 'personName' => 'Zákazník', 'price' => '+Obrat', 'currency' => 'Měna'];


		$pieData = [];
		foreach ($cutedData as $r)
			$pieData[] = [$r['personName'], $r['price']];

		$this->addContent(['tabsId' => 'mainTabs', 'selectedTab' => $this->reportParams ['mainTabs']['value'], 'tabs' => [
			['title' => ['icon' => 'icon-table', 'text' => 'Tabulka'], 'content' => [['type' => 'table', 'header' => $h, 'table' => $cutedData]]],
			['title' => ['icon' => 'icon-pie-chart', 'text' => 'Podíly'], 'content' => [['type' => 'graph',
				'graphType' => 'pie', 'graphData' => $pieData]]],
		]]);

		$this->setInfo('title', 'Největší odběratelé');
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'abc', 'icon' => 'icon-sort-alpha-asc', 'title' => 'Abecedně'];
		$d[] = ['id' => 'amount', 'icon' => 'icon-sort-amount-desc', 'title' => 'Finančně'];
		$d[] = ['id' => 'top', 'icon' => 'icon-trophy', 'title' => 'TOP 10'];
		return $d;
	}

}

