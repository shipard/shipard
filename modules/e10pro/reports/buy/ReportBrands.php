<?php

namespace e10Pro\reports\buy;

use e10doc\core\libs\E10Utils;



/**
 * Class ReportBrands
 * @package e10Pro\reports\buy
 */
class ReportBrands extends \e10doc\core\libs\reports\GlobalReport
{
	var $units;
	var $currencies;

	function init ()
	{
		$periodFlags = array('enableAll', 'quarters', 'halfs', 'years');
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', array('flags' => $periodFlags));

		switch ($this->subReportId)
		{
			case 'types':
			case 'items':
				$this->addParamItemsBrands (); break;
		}

		parent::init();

		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$this->setInfo('icon', 'icon-caret-square-o-down');
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

		$this->addParam('switch', 'itemBrand', array ('title' => 'Značka', 'switch' => $this->itemsBrands));
	}


	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Summary (); break;
			case 'types': $this->createContent_Types (); break;
			case 'items': $this->createContent_Items (); break;
		}
	}

	function createContent_Summary ()
	{
		$q[] = 'SELECT items.brand as itemBrand, brands.fullName as itemBrandName, [rows].unit as rowUnit, heads.homeCurrency as currency,';
		array_push ($q, ' SUM([rows].quantity) as quantity, SUM([rows].taxBaseHc) as price');
		array_push ($q, ' FROM e10doc_core_rows as [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx');
		array_push ($q, ' WHERE heads.docState = 4000 ');

		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		array_push ($q, ' AND docType IN %in AND [rows].invDirection = 1 AND heads.initState = 0', array('stockin', 'purchase', 'invni', 'cash'));
		array_push ($q, ' GROUP BY items.brand, [rows].unit, heads.homeCurrency');
		array_push ($q, ' ORDER BY itemBrandName, rowUnit');

		$rows = $this->app->db()->query($q);

		$data = array ();

		$sums = array ();
		$sums['ALL'] = ['rows' => 0, 'itemBrandName' => 'Celkem', 'price' => 0.0, '_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['itemBrandName' => 3]]];
		$sums['SUB'] = ['rows' => 0, 'price' => 0.0, '_options' => ['class' => 'subtotal', 'colSpan' => ['itemBrandName' => 3]]];

		$firstRow = TRUE;

		forEach ($rows as $r)
		{
			$newItem = $r->toArray();

			$newItem ['rowUnit'] = $this->units[$r['rowUnit']]['shortcut'];
			$newItem ['currency'] = $this->currencies[$r['currency']]['shortcut'];

			if (!$r['itemBrandName'])
				$newItem['itemBrandName'] = '-- neuvedeno --';

			if (isset($sums['SUB']['itemBrandName']) && $newItem['itemBrandName'] !== $sums['SUB']['itemBrandName'])
			{
				if ($sums['SUB']['rows'] > 1)
					$data[] = $sums['SUB'];
				$sums['SUB'] = ['rows' => 0, 'price' => 0.0, '_options' => ['class' => 'subtotal', 'colSpan' => ['itemBrandName' => 3]]];
			}
			$sums['SUB']['itemBrandName'] = $newItem['itemBrandName'];

			if (($sums['SUB']['rows'] < 1) && ($firstRow == FALSE))
				$newItem['_options'] = ['beforeSeparator' => 'separator'];

			$firstRow = FALSE;
			$data[] = $newItem;

			foreach ($sums as $k => $s)
			{
				$sums[$k]['price'] += $newItem['price'];
				$sums[$k]['rows']++;
			}
		}

		if ($sums['SUB']['rows'] > 1)
			$data[] = $sums['SUB'];
		$data[] = $sums['ALL'];

		$h = array ('itemBrandName' => 'Značka', 'quantity' => ' Množství', 'rowUnit' => 'jed.', 'price' => ' Cena'/*, 'currency' => 'Měna'*/);
		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data));

		$this->setInfo('title', 'Nákupy podle značek');
		$this->setInfo('note', '1', 'Všechny ceny jsou bez DPH v domácí měně.');
	}

	function createContent_Types ()
	{
		$q[] = 'SELECT items.brand as itemBrand, brands.fullName as itemBrandName, items.itemType as itemType, types.fullName as itemTypeName,';
		array_push ($q, ' [rows].unit as rowUnit, heads.homeCurrency as currency,');
		array_push ($q, ' SUM([rows].quantity) as quantity, SUM([rows].taxBaseHc) as price');
		array_push ($q, ' FROM e10doc_core_rows as [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_itemtypes AS types ON items.itemType = types.ndx');
		array_push ($q, ' WHERE heads.docState = 4000 ');

		if ($this->reportParams ['itemBrand']['value'] != '-1')
			array_push ($q, ' AND items.brand = %i', $this->reportParams ['itemBrand']['value']);

		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		array_push ($q, ' AND docType IN %in AND [rows].invDirection = 1 AND heads.initState = 0', array('stockin', 'purchase', 'invni', 'cash'));
		array_push ($q, ' GROUP BY items.brand, items.itemType, [rows].unit, heads.homeCurrency');
		array_push ($q, ' ORDER BY itemBrandName, itemTypeName, rowUnit');

		$rows = $this->app->db()->query($q);

		$data = array ();

		$lastBrandName = FALSE;
		$totalSums = array ();
		$brandSums = array ();
		$brandItems = 0;

		forEach ($rows as $r)
		{

			if ($lastBrandName !== $r['itemBrandName'])
			{
				$price = 0;
				foreach ($brandSums as $bs)
				{
					if ($brandItems > count ($brandSums))
					{
						$data[] = $bs;
					}
					$price += $bs['price'];
				}
				if (count ($brandSums) > 1)
				{
					$bsTotal = array('itemTypeName' => $lastBrandName, 'price' => $price,
						'currency' => $this->currencies[$r['currency']]['shortcut'],
						'_options' => array('class' => 'subtotal', 'colSpan' => ['itemTypeName' => 3]));
					if (!$bsTotal['itemTypeName'])
						$bsTotal['itemTypeName'] = '-- značka neuvedena --';
					$data[] = $bsTotal;
				}

				if ($this->reportParams ['itemBrand']['value'] == '-1')
				{
					$hdr = array ('itemTypeName' => $r['itemBrandName'],
						'_options' => array ('class' => 'subheader separator', 'colSpan' => array ('itemTypeName' => 4)));
					if (!$r['itemBrandName'])
						$hdr['itemTypeName'] = '-- značka neuvedena --';

					$data[] = $hdr;
				}
				$lastBrandName = $r['itemBrandName'];
				$brandSums = array ();
				$brandItems = 0;
			}
			$brandItems++;

			$newItem = $r->toArray();

			$c = $newItem ['currency'];
			$u = $newItem ['rowUnit'];
			$uc = $c.'-'.$u;

			$newItem ['rowUnit'] = $this->units[$r['rowUnit']]['shortcut'];
			$newItem ['currency'] = $this->currencies[$r['currency']]['shortcut'];

			if (!$r['itemBrandName'])
				$newItem['itemBrandName'] = '-- značka neuvedena --';
			$data[] = $newItem;

			if (!isset($brandSums [$uc]))
				$brandSums [$uc] = array ('itemTypeName' => $newItem['itemBrandName'], 'quantity' => 0.0, 'price' => 0.0,
																	'rowUnit' => $this->units[$r['rowUnit']]['shortcut'], 'currency' => $this->currencies[$r['currency']]['shortcut'],
																	'_options' => array ('class' => 'subtotal'));

			$brandSums [$uc]['quantity'] += $r['quantity'];
			$brandSums [$uc]['price'] += $r['price'];

			if (!isset($totalSums [$uc]))
				$totalSums [$uc] = array ('itemTypeName' => 'Celkem', 'quantity' => 0.0, 'price' => 0.0,
					'rowUnit' => $this->units[$r['rowUnit']]['shortcut'], 'currency' => $this->currencies[$r['currency']]['shortcut'],
					'_options' => array ('class' => 'sumtotal'));

			$totalSums [$uc]['quantity'] += $r['quantity'];
			$totalSums [$uc]['price'] += $r['price'];
		}

		$price = 0;
		foreach ($brandSums as $bs)
		{
			if ($brandItems > count ($brandSums))
			{
				$data[] = $bs;
			}
			$price += $bs['price'];
		}
		if (count ($brandSums) > 1)
		{
			$bsTotal = array('itemTypeName' => $lastBrandName, 'price' => $price,
				'currency' => $this->currencies[$r['currency']]['shortcut'],
				'_options' => array('class' => 'subtotal', 'colSpan' => ['itemTypeName' => 4]));
			$data[] = $bsTotal;
		}

		$price = 0;
		$firstRow = TRUE;
		foreach ($totalSums as $ts)
		{
			if ($firstRow == TRUE)
			{
				$ts['_options']['beforeSeparator'] = 'separator';
			}
			$data[] = $ts;
			$price += $ts['price'];
			$firstRow = FALSE;
		}
		if (count ($totalSums) > 1)
		{
			$tsTotal = array('itemTypeName' => 'Celkem', 'price' => $price,
				'currency' => $this->currencies[$r['currency']]['shortcut'],
				'_options' => array('class' => 'sumtotal', 'colSpan' => ['itemTypeName' => 3]));
			$data[] = $tsTotal;
		}

		$h = array ('itemTypeName' => 'Typ položek',
								'quantity' => ' Množství', 'rowUnit' => 'jed.', 'price' => ' Cena'/*, 'currency' => 'Měna'*/);
		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE));

		$this->setInfo('title', 'Nákup typů položek podle značek');
		$this->setInfo('note', '1', 'Všechny ceny jsou bez DPH v domácí měně.');
	}

	function createContent_Items ()
	{
		$q[] = 'SELECT items.brand as itemBrand, brands.fullName as itemBrandName, items.ndx as itemNdx, items.id as itemId,';
		array_push ($q, ' [rows].unit as rowUnit, heads.homeCurrency as currency,');
		array_push ($q, ' items.fullName as itemName, SUM([rows].quantity) as quantity, SUM([rows].taxBaseHc) as price');
		array_push ($q, ' FROM e10doc_core_rows as [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_itemtypes AS types ON items.itemType = types.ndx');
		array_push ($q, ' WHERE heads.docState = 4000 ');

		if ($this->reportParams ['itemBrand']['value'] != '-1')
			array_push ($q, ' AND items.brand = %i', $this->reportParams ['itemBrand']['value']);

		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		array_push ($q, ' AND docType IN %in AND [rows].invDirection = 1 AND heads.initState = 0', array('stockin', 'purchase', 'invni', 'cash'));
		array_push ($q, ' GROUP BY [rows].item, [rows].unit, heads.homeCurrency');
		array_push ($q, ' ORDER BY itemBrandName, items.fullName, rowUnit');

		$rows = $this->app->db()->query($q);

		$data = array ();

		$lastBrandName = FALSE;
		$totalSums = array ();
		$brandSums = array ();

		forEach ($rows as $r)
		{
			if ($lastBrandName !== $r['itemBrandName'])
			{
				$price = 0;
				foreach ($brandSums as $bs)
				{
					$data[] = $bs;
					$price += $bs['price'];
				}
				if (count ($brandSums) > 1)
				{
					$bsTotal = array('wn' => $lastBrandName, 'price' => $price,
						'currency' => $this->currencies[$r['currency']]['shortcut'],
						'_options' => array('class' => 'subtotal', 'colSpan' => ['wn' => 4]));
					if (!$bsTotal['wn'])
						$bsTotal['wn'] = '-- značka neuvedena --';
					$data[] = $bsTotal;
				}

				if ($this->reportParams ['itemBrand']['value'] == '-1')
				{
					$hdr = array ('wn' => $r['itemBrandName'],
												'_options' => array ('class' => 'subheader separator', 'colSpan' => array ('wn' => 5)));
					if (!$r['itemBrandName'])
						$hdr['wn'] = '-- značka neuvedena --';
					$data[] = $hdr;
				}
				$lastBrandName = $r['itemBrandName'];
				$brandSums = array ();
			}
			$newItem = $r->toArray();

			$c = $newItem ['currency'];
			$u = $newItem ['rowUnit'];
			$uc = $c.'-'.$u;

			$newItem ['rowUnit'] = $this->units[$r['rowUnit']]['shortcut'];
			$newItem ['currency'] = $this->currencies[$r['currency']]['shortcut'];
			$newItem ['wn'] = array ('text'=> $r ['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $r ['itemNdx']);

			if (!$r['itemBrandName'])
				$newItem['itemBrandName'] = '-- značka neuvedena --';
			$data[] = $newItem;

			if (!isset($brandSums [$uc]))
				$brandSums [$uc] = array ('wn' => $newItem['itemBrandName'], 'quantity' => 0.0, 'price' => 0.0,
					'rowUnit' => $this->units[$r['rowUnit']]['shortcut'], 'currency' => $this->currencies[$r['currency']]['shortcut'],
					'_options' => array ('class' => 'subtotal', 'colSpan' => ['wn' => 2]));

			$brandSums [$uc]['quantity'] += $r['quantity'];
			$brandSums [$uc]['price'] += $r['price'];

			if (!isset($totalSums [$uc]))
				$totalSums [$uc] = array ('wn' => 'Celkem', 'quantity' => 0.0, 'price' => 0.0,
					'rowUnit' => $this->units[$r['rowUnit']]['shortcut'], 'currency' => $this->currencies[$r['currency']]['shortcut'],
					'_options' => array ('class' => 'sumtotal', 'colSpan' => ['wn' => 2]));

			$totalSums [$uc]['quantity'] += $r['quantity'];
			$totalSums [$uc]['price'] += $r['price'];
		}

		$price = 0;
		foreach ($brandSums as $bs)
		{
			$data[] = $bs;
			$price += $bs['price'];
		}
		if (count ($brandSums) > 1)
		{
			$bsTotal = array('wn' => $lastBrandName, 'price' => $price,
				'currency' => $this->currencies[$r['currency']]['shortcut'],
				'_options' => array('class' => 'subtotal', 'colSpan' => ['wn' => 4]));
			$data[] = $bsTotal;
		}

		$price = 0;
		$firstRow = TRUE;
		foreach ($totalSums as $ts)
		{
			if ($firstRow == TRUE)
			{
				$ts['_options']['beforeSeparator'] = 'separator';
			}
			$data[] = $ts;
			$price += $ts['price'];
			$firstRow = FALSE;
		}
		if (count ($totalSums) > 1)
		{
			$tsTotal = array('wn' => 'Celkem', 'price' => $price,
				'currency' => $this->currencies[$r['currency']]['shortcut'],
				'_options' => array('class' => 'sumtotal', 'colSpan' => ['wn' => 4]));
			$data[] = $tsTotal;
		}

		$h = array ('wn' => 'Položka', 'itemName' => 'Název',
			'quantity' => ' Množství', 'rowUnit' => 'jed.', 'price' => ' Cena' /*, 'currency' => 'Měna'*/);
		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE));

		$this->setInfo('title', 'Nákupy položek podle značek');
		$this->setInfo('note', '1', 'Všechny ceny jsou bez DPH v domácí měně.');
	}

	public function subReportsList ()
	{
		$d[] = array ('id' => 'sum', 'icon' => 'icon-plus-square', 'title' => 'Sumárně');
		$d[] = array ('id' => 'types', 'icon' => 'icon-archive', 'title' => 'Typy');
		$d[] = array ('id' => 'items', 'icon' => 'e10-witems-items', 'title' => 'Položky');
		return $d;
	}
}

