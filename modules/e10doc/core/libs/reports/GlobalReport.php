<?php

namespace e10doc\core\libs\reports;
use \e10doc\core\libs\GlobalParams;
use \e10\uiutils;


class GlobalReport extends \e10\GlobalReport
{
	protected function createParamsObject ()
	{
		$this->params = new GlobalParams ($this->app);
	}

	protected function addParamItemsTypes ()
	{
		$itemKind = uiutils::detectParamValue('itemKind', 0);

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

	function fiscalMonths ($fiscalPeriod, $periodType = FALSE, $count = 0)
	{
		$fy = FALSE;
		$fm = FALSE;
		if ($fiscalPeriod[0] === 'Y')
			$fy = intval(substr($fiscalPeriod, 1));
		else
			$fm = intval($fiscalPeriod);

		$endMonthRec = NULL;
		if ($fm)
		{
			$endMonthRec = $this->app->db()->query('SELECT * FROM [e10doc_base_fiscalmonths] WHERE ndx = %i', $fm)->fetch ();
			$fy = $endMonthRec['fiscalYear'];
		}

		$q = [];
		array_push($q, 'SELECT * FROM [e10doc_base_fiscalmonths] WHERE 1');

		if ($periodType !== FALSE)
		{
			if (is_array($periodType))
				array_push($q, ' AND fiscalType IN %in', $periodType);
			else
				array_push($q, ' AND fiscalType = %i', $periodType);
		}
		else
			array_push($q, ' AND fiscalType IN (0, 2)');

		if (!$count)
			array_push($q, ' AND fiscalYear = %i', $fy);

		if ($endMonthRec)
			array_push($q, ' AND [globalOrder] <= %i', $endMonthRec['globalOrder']);

		array_push ($q, ' ORDER BY globalOrder DESC');

		if ($count)
			array_push($q, ' LIMIT %i', $count);

		$months = $this->app->db()->query($q);

		$monthList = [];
		forEach ($months as $m)
			$monthList[] = $m['ndx'];

		return $monthList;
	}
}
