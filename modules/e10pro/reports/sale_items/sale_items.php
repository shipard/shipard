<?php

namespace E10Pro\Reports\Sale_Items;

use E10\utils, E10Doc\Core\e10utils, \E10\uiutils, e10doc\core\libs\Aggregate, e10doc\core\libs\AggregateDocRows, E10Doc\Core\WidgetAggregate;


/**
 * Class Sales
 * @package E10Pro\Reports\Sale_Items
 */
class Sales extends \e10doc\core\libs\AggregateDocRows
{
}


/**
 * Class reportItems
 * @package E10Pro\Reports\Sale_Items
 */
class reportItems extends \e10doc\core\libs\reports\GlobalReport
{
	var $units;
	var $currencies;
	var $period;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', array('flags' => ['enableAll', 'quarters', 'halfs', 'years']));

		if ($this->subReportId === 'sum' || $this->subReportId === '')
			$this->addParam ('switch', 'groupBy', ['title' => 'Přehled dle',
											 'switch' => ['1' => 'Položek', '2' => 'Účetních skupin', '3' => 'Typů', '4' => 'Značek', '5' => 'Druhu položky']]);

		$this->addParam ('switch', 'itemKind', ['title' => 'Druh položek', 'switch' => ['999' => 'Vše', '0' => 'Služba', '1' => 'Zásoba']]);

		if ($this->subReportId === 'items' || $this->subReportId === 'sum' || $this->subReportId === '')
			$this->addParamItemsTypes ();

		$this->addParamItemsBrands ();
		$this->addParam ('hidden', 'mainTabs');

		parent::init();

		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$this->setInfo('icon', 'reportMonthlyReport');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('note', '1', 'Všechny částky jsou bez DPH');
	}

	protected function addParamItemsBrands ()
	{
		$itemKind = uiutils::detectParamValue('itemKind', 0);

		$q[] = 'select DISTINCT brands.ndx, brands.fullName, brands.shortName FROM e10_witems_items AS items';
		array_push($q, ' LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx');
		array_push($q, ' WHERE items.brand != 0');
		if ($itemKind != 999)
			array_push($q, ' AND items.itemKind = %i', $itemKind);
		array_push($q, ' ORDER BY brands.fullName, brands.ndx');

		$this->itemsBrands ['-1'] = 'Vše';
		$this->itemsBrands ['0'] = '-- neuvedeno --';

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['fullName'])
				$this->itemsBrands [$r['ndx']] = $r['fullName'];
			else
				$this->itemsBrands [$r['ndx']] = $r['shortName'];
		}

		$this->addParam('switch', 'itemBrand', ['title' => 'Značka', 'switch' => $this->itemsBrands]);
	}

	protected function addParamItemsTypes ()
	{
		$itemKind = uiutils::detectParamValue('itemKind', 999);

		$q[] = 'select DISTINCT types.ndx, types.fullName, types.shortName FROM e10_witems_items AS items';
		array_push($q, ' LEFT JOIN e10_witems_itemtypes AS types ON items.itemType = types.ndx');
		array_push($q, ' WHERE items.itemType != 0');
		if ($itemKind != 999)
			array_push($q, ' AND items.itemKind = %i', $itemKind);
		array_push($q, ' ORDER BY types.fullName, types.ndx');

		$itemsTypes ['-1'] = 'Vše';
		$itemsTypes ['0'] = '-- neuvedeno --';

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['fullName'])
				$itemsTypes[$r['ndx']] = $r['fullName'];
			else
				$itemsTypes[$r['ndx']] = $r['shortName'];
		}

		$this->addParam('switch', 'itemType', ['title' => 'Typ', 'switch' => $itemsTypes]);
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Sum (); break;
			case 'items': $this->createContent_Items (); break;
			case 'types': $this->createContent_Types (); break;
		}
	}

	function createContent_Sum ()
	{
		$this->period = Aggregate::periodDaily;
		if ($this->reportParams ['fiscalPeriod']['value'] == 0 || $this->reportParams ['fiscalPeriod']['value'][0] === 'Y' || strstr ($this->reportParams ['fiscalPeriod']['value'], ',') !== FALSE)
			$this->period = Aggregate::periodMonthly;

		$engine = new Sales($this->app);
		$engine->setFiscalPeriod($this->reportParams ['fiscalPeriod']['value']);
		$engine->setReportPeriod($this->period);
		$engine->groupBy = intval($this->reportParams ['groupBy']['value']);

		if ($this->reportParams ['itemBrand']['value'] != -1)
		{
			$engine->itemBrand = $this->reportParams ['itemBrand']['value'];
			$this->setInfo('param', 'Značka', $this->reportParams ['itemBrand']['activeTitle']);
		}
		if ($this->reportParams ['itemType']['value'] != -1)
		{
			$engine->itemType = $this->reportParams ['itemType']['value'];
			$this->setInfo('param', 'Typ', $this->reportParams ['itemType']['activeTitle']);
		}

		$engine->init();
		$engine->create();

		$this->addContent(['tabsId' => 'mainTabs', 'selectedTab' => $this->reportParams ['mainTabs']['value'], 'tabs' => [
			['title' => ['icon' => 'icon-table', 'text' => 'Tabulka'], 'content' => [['type' => 'table', 'header' => $engine->header, 'table' => $engine->data, 'main' => TRUE]]],
			['title' => ['icon' => 'icon-bar-chart-o', 'text' => 'Sloupce'], 'content' => [$engine->graphBar]],
			['title' => ['icon' => 'icon-line-chart', 'text' => 'Čáry'], 'content' => [$engine->graphLine]],
			['title' => ['icon' => 'icon-pie-chart', 'text' => 'Podíly'], 'content' => [$engine->graphDonut]],
			['title' => ['icon' => 'icon-file', 'text' => 'Vše'],
				'content' => [
					['type' => 'table', 'header' => $engine->header, 'table' => $engine->data], $engine->graphBar, $engine->graphDonut]
				]
			]
		]);

		switch ($this->period)
		{
			case Aggregate::periodDaily:
				$this->setInfo('title', 'Denní přehled tržeb');
				break;
			case Aggregate::periodMonthly:
				$this->setInfo('title', 'Měsíční přehled tržeb');
				break;
		}

		$this->setInfo('note', '1', 'Všechny částky jsou bez DPH');
	}

	function createContent_Items ()
	{
		$q[] = 'SELECT items.ndx as itemNdx, items.fullName as itemName, items.id as itemId, [rows].unit as rowUnit, heads.homeCurrency as currency,';
		array_push ($q, ' SUM([rows].quantity) as quantity, SUM([rows].taxBaseHc) as price');
		array_push ($q, ' FROM e10doc_core_rows as [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx');
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

		array_push ($q, ' AND docType IN %in', array('invno', 'cashreg'));
		array_push ($q, ' GROUP BY [rows].item, [rows].unit, heads.homeCurrency');
		array_push ($q, ' ORDER BY itemName, rowUnit');

		$rows = $this->app->db()->query($q);

		$data = [];

		forEach ($rows as $r)
		{
			$newItem = $r->toArray();
			$newItem ['rowUnit'] = $this->units[$r['rowUnit']]['shortcut'];
			$newItem ['currency'] = $this->currencies[$r['currency']]['shortcut'];
			$newItem ['itemId'] = ['text'=> $r ['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $r ['itemNdx']];
			$data[] = $newItem;
		}

		$h = ['itemId' => ' Položka', 'itemName' => 'Název', 'quantity' => ' Množství', 'rowUnit' => 'jed.',
					'price' => '+Cena celkem', 'currency' => 'Měna'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Prodeje jednotlivých položek');
	}

	function createContent_Types ()
	{
		$q[] = 'SELECT types.ndx as typeNdx, types.fullName as typeName, [rows].unit as rowUnit, heads.homeCurrency as currency,';
		array_push ($q, ' SUM([rows].quantity) as quantity, SUM([rows].taxBaseHc) as price');
		array_push ($q, ' FROM e10doc_core_rows as [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_itemtypes AS types ON items.itemType = types.ndx');
		array_push ($q, ' WHERE heads.docState = 4000 ');

		e10utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		if ($this->reportParams ['itemKind']['value'] != 999)
		{
			array_push ($q, ' AND items.itemKind = %i', $this->reportParams ['itemKind']['value']);
			$this->setInfo('param', 'Druh', $this->reportParams ['itemKind']['activeTitle']);
		}

		if ($this->reportParams ['itemBrand']['value'] != -1)
		{
			array_push ($q, ' AND items.brand = %i', $this->reportParams ['itemBrand']['value']);
			$this->setInfo('param', 'Značka', $this->reportParams ['itemBrand']['activeTitle']);
		}

		array_push ($q, ' AND docType IN %in', ['invno', 'cashreg']);
		array_push ($q, ' GROUP BY items.itemType, [rows].unit, heads.homeCurrency');
		array_push ($q, ' ORDER BY typeName, rowUnit');

		$rows = $this->app->db()->query($q);

		$data = [];

		forEach ($rows as $r)
		{
			$newItem = $r->toArray();
			$newItem ['rowUnit'] = $this->units[$r['rowUnit']]['shortcut'];
			$newItem ['currency'] = $this->currencies[$r['currency']]['shortcut'];
			$data[] = $newItem;
		}

		$h = ['typeName' => 'Typ', 'quantity' => ' Množství', 'rowUnit' => 'jed.',
					'price' => '+Cena celkem', 'currency' => 'Měna'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Prodeje podle typů položek');
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icon' => 'system/detailDetail', 'title' => 'Přehled'];
		$d[] = ['id' => 'items', 'icon' => 'detailReportItems', 'title' => 'Položky'];
		$d[] = ['id' => 'types', 'icon' => 'detailReportTypes', 'title' => 'Typy'];
		return $d;
	}
}


