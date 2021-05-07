<?php

namespace E10Pro\Reports\Inventory;

use e10doc\core\libs\E10Utils;


/**
 * Class reportSale
 */
class reportSale extends \e10doc\core\libs\reports\GlobalReport
{
	var $units;
	var $currencies;

	function init ()
	{
		$periodFlags = ['enableAll', 'quarters', 'halfs', 'years'];
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => $periodFlags]);
		$this->addParam ('warehouse');
		$this->addParamItemsBrands ();

		parent::init();

		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$this->setInfo('icon', 'icon-arrow-circle-up');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('param', 'Sklad', $this->reportParams ['warehouse']['activeTitle']);
		if (isset($this->reportParams ['itemBrand']['activeTitle']))
			$this->setInfo('param', 'Značka', $this->reportParams ['itemBrand']['activeTitle']);
	}

	protected function addParamItemsBrands ()
	{
		$q = 'SELECT * from [e10_witems_brands] WHERE docState != 9800 ORDER BY fullName';

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

	function createContent ()
	{
		$this->createContent_Items ();
	}

	function createContent_Items ()
	{
		$q[] = 'SELECT items.brand as itemBrand, brands.fullName as itemBrandName, items.ndx as itemNdx, items.id as itemId,';
		array_push ($q, ' [rows].unit as rowUnit, heads.homeCurrency as currency,');
		array_push ($q, ' heads.ndx as headNdx, heads.docType as docType, heads.dateAccounting as dateAccounting, heads.docNumber as docNumber,');
		array_push ($q, ' items.fullName as itemName, [rows].quantity as quantity, [rows].taxBaseHc as price, (journal.price*-1) as invPrice');
		array_push ($q, ' FROM e10doc_core_rows as [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_itemtypes AS types ON items.itemType = types.ndx');
		array_push ($q, ' LEFT JOIN e10doc_inventory_journal as journal ON (journal.docHead = [rows].document AND journal.docRow = [rows].ndx)');
		array_push ($q, ' WHERE heads.docState = 4000 ');

		if ($this->reportParams ['warehouse']['value'] != 0)
			array_push ($q, ' AND heads.warehouse = %i', $this->reportParams ['warehouse']['value']);

		if ($this->reportParams ['itemBrand']['value'] != '-1')
			array_push ($q, ' AND items.brand = %i', $this->reportParams ['itemBrand']['value']);

		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		array_push ($q, ' AND docType IN %in AND [rows].invDirection = -1', ['invno', 'cashreg']);
		array_push ($q, ' ORDER BY heads.docType, heads.dateAccounting, heads.docNumber, [rows].ndx');

		$rows = $this->app->db()->query($q);

		$lastDocType = '';
		$data = [];
		$dtSums = [];
		$dtSums ['ALL'] = ['dateAccounting' => 'Σ Celkem', 'price' => 0.0, 'invPrice' => 0.0, 'profit' => 0.0, '_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['dateAccounting' => 6]]];

		forEach ($rows as $r)
		{
			$dt = $r['docType'];

			if ($lastDocType !== $dt)
			{
				if ($lastDocType !== '')
				{
					$dtSums[$lastDocType]['profit'] = $dtSums[$lastDocType]['price'] - $dtSums[$lastDocType]['invPrice'];
					if ($dtSums[$lastDocType]['price'])
						$dtSums[$lastDocType]['margin'] = ($dtSums[$lastDocType]['price'] - $dtSums[$lastDocType]['invPrice']) / $dtSums[$lastDocType]['price'] * 100;
					else
						$dtSums[$lastDocType]['margin'] = 0.0;
					$data[] = $dtSums[$lastDocType];
				}

				$docType = $this->app->cfgItem ('e10.docs.types.'.$dt, FALSE);
				$hdr = [
					'dateAccounting' => ['text' => $docType['pluralName'], 'icon' => $docType['icon']],
					'_options' => ['class' => 'subheader separator', 'colSpan' => ['dateAccounting' => 10]]
					];
				$data[] = $hdr;
			}

			$newItem = $r->toArray();
			$newItem ['profit'] = $newItem['price'] - $newItem['invPrice'];
			if ($newItem['price'])
				$newItem ['margin'] = ($newItem['price'] - $newItem['invPrice']) / $newItem['price'] * 100;
			else
				$newItem ['margin'] = 0.0;

			$newItem ['rowUnit'] = $this->units[$r['rowUnit']]['shortcut'];
			$newItem ['currency'] = $this->currencies[$r['currency']]['shortcut'];
			$newItem ['wn'] = ['text'=> $r ['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $r ['itemNdx']];
			$newItem ['dn'] = ['text'=> $r ['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r ['headNdx']];

			$data[] = $newItem;

			if (!isset($dtSums [$dt]))
				$dtSums [$dt] = ['dateAccounting' => ['text' => $docType['pluralName'], 'icon' => $docType['icon']], 'price' => 0.0, 'invPrice' => 0.0, 'profit' => 0.0, '_options' => ['class' => 'subtotal', 'colSpan' => ['dateAccounting' => 6]]];

			$dtSums [$dt]['price'] += $r['price'];
			$dtSums [$dt]['invPrice'] += $r['invPrice'];

			$dtSums ['ALL']['price'] += $r['price'];
			$dtSums ['ALL']['invPrice'] += $r['invPrice'];

			$lastDocType = $dt;
		}

		if (isset($dtSums[$lastDocType]))
		{
			$dtSums[$lastDocType]['profit'] = $dtSums[$lastDocType]['price'] - $dtSums[$lastDocType]['invPrice'];
			if ($dtSums[$lastDocType]['price'])
				$dtSums[$lastDocType]['margin'] = ($dtSums[$lastDocType]['price'] - $dtSums[$lastDocType]['invPrice']) / $dtSums[$lastDocType]['price'] * 100;
			else
				$dtSums[$lastDocType]['margin'] = 0.0;
			$data[] = $dtSums[$lastDocType];
		}

		if (isset($dtSums['ALL']))
		{
			$dtSums['ALL']['profit'] = $dtSums['ALL']['price'] - $dtSums['ALL']['invPrice'];
			if ($dtSums['ALL']['price'])
				$dtSums['ALL']['margin'] = ($dtSums['ALL']['price'] - $dtSums['ALL']['invPrice']) / $dtSums['ALL']['price'] * 100;
			else
				$dtSums['ALL']['margin'] = 0.0;
			$data[] = $dtSums['ALL'];
		}

		$h = [
			'dateAccounting' => 'Datum', 'dn' => 'Doklad','wn' => 'Položka', 'itemName' => 'Název',
			'quantity' => ' Množství', 'rowUnit' => 'jed.', 'price' => ' Cena Prodej', /*'currency' => 'Měna',*/
			'invPrice' => ' Cena Sklad', 'profit' => ' Zisk', 'margin' => ' Marže %'
		];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Prodej zásob');
		$this->setInfo('note', '1', 'Všechny ceny jsou bez DPH v domácí měně.');
		$this->paperOrientation = 'landscape';
	}
}

/**
 * Class reportBuy
 */
class reportBuy extends \e10doc\core\libs\reports\GlobalReport
{
	var $units;
	var $currencies;

	function init ()
	{
		$periodFlags = ['enableAll', 'quarters', 'halfs', 'years'];
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => $periodFlags]);
		$this->addParamItemsBrands ();

		parent::init();

		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$this->setInfo('icon', 'icon-arrow-circle-down');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		if (isset($this->reportParams ['itemBrand']['activeTitle']))
			$this->setInfo('param', 'Značka', $this->reportParams ['itemBrand']['activeTitle']);
	}

	protected function addParamItemsBrands ()
	{
		$q = 'SELECT * from [e10_witems_brands] WHERE docState != 9800 ORDER BY fullName';

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

	function createContent ()
	{
		$this->createContent_Items ();
	}

	function createContent_Items ()
	{
		$q[] = 'SELECT items.brand as itemBrand, brands.fullName as itemBrandName, items.ndx as itemNdx, items.id as itemId,';
		array_push ($q, ' [rows].unit as rowUnit, heads.homeCurrency as currency,');
		array_push ($q, ' heads.ndx as headNdx, heads.docType as docType, heads.dateAccounting as dateAccounting, heads.docNumber as docNumber,');
		array_push ($q, ' items.fullName as itemName, [rows].quantity as quantity, journal.price as invPrice');
		array_push ($q, ' FROM e10doc_core_rows as [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_itemtypes AS types ON items.itemType = types.ndx');
		array_push ($q, ' LEFT JOIN e10doc_inventory_journal as journal ON (journal.docHead = [rows].document AND journal.docRow = [rows].ndx)');
		array_push ($q, ' WHERE heads.docState = 4000 ');

		if ($this->reportParams ['itemBrand']['value'] != '-1')
			array_push ($q, ' AND items.brand = %i', $this->reportParams ['itemBrand']['value']);

		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		array_push ($q, ' AND docType IN %in AND [rows].invDirection = 1', ['stockin', 'purchase', 'invni', 'cash']);
		array_push ($q, ' ORDER BY heads.docType, heads.dateAccounting, heads.docNumber, [rows].ndx');

		$rows = $this->app->db()->query($q);

		$lastDocType = '';
		$data = [];
		$dtSums = [];
		$dtSums ['ALL'] = ['dateAccounting' => 'Σ Celkem', 'invPrice' => 0.0, '_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['dateAccounting' => 6]]];

		forEach ($rows as $r)
		{
			$dt = $r['docType'];

			if ($lastDocType !== $dt)
			{
				if ($lastDocType !== '')
				{
					$data[] = $dtSums[$lastDocType];
				}

				$docType = $this->app->cfgItem ('e10.docs.types.'.$dt, FALSE);
				$hdr = [
					'dateAccounting' => ['text' => $docType['pluralName'], 'icon' => $docType['icon']],
					'_options' => ['class' => 'subheader separator', 'colSpan' => ['dateAccounting' => 6]]
				];
				$data[] = $hdr;
			}

			$newItem = $r->toArray();

			$newItem ['rowUnit'] = $this->units[$r['rowUnit']]['shortcut'];
			$newItem ['currency'] = $this->currencies[$r['currency']]['shortcut'];
			$newItem ['wn'] = ['text'=> $r ['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $r ['itemNdx']];
			$newItem ['dn'] = ['text'=> $r ['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r ['headNdx']];

			$data[] = $newItem;

			if (!isset($dtSums [$dt]))
				$dtSums [$dt] = ['dateAccounting' => ['text' => $docType['pluralName'], 'icon' => $docType['icon']], 'invPrice' => 0.0, '_options' => ['class' => 'subtotal', 'colSpan' => ['dateAccounting' => 6]]];

			$dtSums [$dt]['invPrice'] += $r['invPrice'];

			$dtSums ['ALL']['invPrice'] += $r['invPrice'];

			$lastDocType = $dt;
		}

		if (isset($dtSums[$lastDocType]))
		{
			$data[] = $dtSums[$lastDocType];
		}

		if (isset($dtSums['ALL']))
		{
			$data[] = $dtSums['ALL'];
		}

		$h = [
			'dateAccounting' => 'Datum', 'dn' => 'Doklad','wn' => 'Položka', 'itemName' => 'Název',
			'quantity' => ' Množství', 'rowUnit' => 'jed.', /*'currency' => 'Měna',*/
			'invPrice' => ' Cena Sklad'
		];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Nákup zásob');
		$this->setInfo('note', '1', 'Všechny ceny jsou bez DPH v domácí měně.');
		$this->paperOrientation = 'landscape';
	}
}

