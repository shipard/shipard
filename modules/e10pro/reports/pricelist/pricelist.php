<?php

namespace E10Pro\Reports\Pricelist;

use \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils, \E10\Widget, \E10\Application;



function webPriceListPurchaseXXX ($app, $params)
{
	$r = new priceListPurchase ($app);
	if (isset($params['mailMode']))
		$r->data['mailMode'] = 1;
	else
		$r->data['webMode'] = 1;
	if (isset($params['propertyPrice']))
		$r->data['propertyPrice'] = $params['propertyPrice'];
	if (isset($params['category']))
		$r->data['category'] = $params['category'];
	if (isset($params['subCategories']))
		$r->data['subCategories'] = $params['subCategories'];
	if (isset($params['tags']))
		$r->data['tags'] = $params['tags'];
	$r->init ();
	$r->createContent ();
	return $r->createReportContent ();
}

/**
 * priceList
 *
 */

class priceList extends \Shipard\Report\GlobalReport
{
	var $byProperty = FALSE;
	var $units;

	function init ()
	{
		if (isset($this->data['webMode']))
		{
			$this->byProperty = 'vykup-cena';
		}
		if (isset($this->data['propertyPrice']))
			$this->byProperty = $this->data['propertyPrice'];

		$this->units = $this->app->cfgItem ('e10.witems.units');

		$this->reportId = 'e10pro.reports.priceList';
		parent::init();
	}

	function createContent ()
	{
		$this->createContent_Default ();
	}

	function createContent_Default ()
	{
		$q = "SELECT * FROM e10_witems_items WHERE docState = 4000";
		$rows = $this->app->db()->query($q);

		$data = array ();

		$pks = [];
		forEach ($rows as $r)
		{
			if ($r['priceBuy'] == 0.0)
				continue;

			$i = ['name' => $r['fullName'], 'unit' => $this->units[$r['defaultUnit']]['shortcut']];
			$i['_options'] = ['cellClasses' => ['unit' => 'unit']];
			$i['price'] = $r['priceBuy'];
			$pks[] = $r['ndx'];
			$data [$r['ndx']] = $i;
		}

		if ($this->byProperty === FALSE)
			$data = array_values($data);
		else
			$data = $this->getPricesFromProperty($pks, $data);

		$h = ['name' => 'Název', 'price' => ' Cena', 'unit' => 'Jedn.'];
		$title = 'Ceník';

		$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data));
	}

	function createContent_ByCategory ($categoryNdx, $subcategories, $tags)
	{
		$catPath = $this->app->cfgItem ('e10.witems.categories.list.'.$categoryNdx, '---');
		$cats = $this->app->cfgItem ("e10.witems.categories.tree".$catPath.'.cats');
		forEach ($cats as $catId => $cat)
		{
			if ($subcategories && !in_array($cat['ndx'], $subcategories))
				continue;
//			$bt [] = array ('id' => 'c'.$cat['ndx'], 'title' => $cat['shortName'], 'active' => 0);
			$q = array();
			$q[] = "SELECT * FROM e10_witems_items AS items WHERE docState = 4000";
			array_push ($q, " AND EXISTS (
												SELECT ndx FROM e10_base_doclinks
												where items.ndx = srcRecId AND srcTableId = %s AND dstTableId = %s AND e10_base_doclinks.dstRecId = %i)",
											'e10.witems.items', 'e10.witems.itemcategories', $cat['ndx']);

			if (count($tags))
			{
				array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE items.ndx = recid AND tableId = %s', 'e10.witems.items');
				array_push ($q, ' AND ([group] = %s', 'witemsTags', ' AND [clsfItem] IN %in', $tags, ')');
				array_push ($q, ')');
			}


			array_push ($q, ' ORDER BY items.orderCashRegister, items.fullName');

			$rows = $this->app->db()->query($q);

			$data = array ();

			$pks = [];
			forEach ($rows as $r)
			{
				if ($r['groupCashRegister'] !== '')
				{
					$i = ['name' => $r['groupCashRegister'], 'header' => 1];
					$i['_options'] = ['colSpan' => ['name' => 3]];
					$data [$r['ndx'].'G'] = $i;
				}
				$i = ['id' => $r['id'], 'name' => $r['fullName'], 'unit' => $this->units[$r['defaultUnit']]['shortcut'], 'item' => 1];
				$i['_options'] = ['cellClasses' => ['id' => 'itemId', 'unit' => 'unit']];

				$i['price'] = $r['priceBuy'];
				$pks[] = $r['ndx'];
				$data [$r['ndx']] = $i;
			}

			if ($this->byProperty === FALSE)
				$data = array_values($data);
			else
			{
				$this->getPricesFromProperty($pks, $data);
				$data = array_values($data);
			}
			if (count($data) !== 0)
				$this->data['prices'][] = ['title' => $cat['fullName'], 'rows' => $data];

			$h = ['id' => 'ID', 'name' => $cat['fullName'], 'price' => ' Cena', 'unit' => 'Jedn.'];
			$title = $cat['fullName'];

			$this->addContent (['type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data]);
		}
	}

	public function getPricesFromProperty ($pks, &$items)
	{
		$q [] = 'SELECT * FROM [e10_base_properties] ';
		array_push ($q, ' WHERE [tableid] = %s', 'e10.witems.items', ' AND property = %s', 'vykup-cena');
		array_push ($q, ' AND recid IN %in', $pks);
		array_push ($q, ' ORDER BY ndx');

		$rows = $this->app->db->query ($q);
		foreach ($rows as $r)
		{
			$itemNdx = $r['recid'];
			$items[$itemNdx]['price'] = $r['valueString'];
			$items[$itemNdx]['priceFromProperty'] = $r['valueString'];
		}
	}
} // createContent_ByCategory


/**
 * priceListPurchase
 *
 */

class priceListPurchase extends priceList
{
	function init ()
	{
		parent::init();
	}

	function createContent ()
	{
		$comboByCats = 0;
		if (isset($this->data['category']))
		{
			$catList = $this->app->cfgItem ('e10.witems.categories.list', []);
			foreach ($catList as $catNdx => $catId)
			{
				if ('.'.$this->data['category'] === $catId)
				{
					$comboByCats = intval ($catNdx);
					break;
				}
			}
		}
		else
			$comboByCats = intval($this->app->cfgItem ('options.e10doc-buy.purchItemComboCats', 0));

		// -- subcategories
		$subCategories = NULL;
		if (isset($this->data['subCategories']))
		{
			$catList = $this->app->cfgItem ('e10.witems.categories.list', []);
			$subCategories = [];
			$sc = explode (',', $this->data['subCategories']);
			foreach ($sc as $scId)
			{
				foreach ($catList as $catNdx => $catId)
				{
					if ('.'.$scId === $catId)
					{
						$subCategories[] = intval ($catNdx);
						break;
					}
				}
			}
		}

		// -- tags
		$tags = [];
		if (isset($this->data['tags']))
		{
			$itemsTags = $this->app->cfgItem ('e10.base.clsf.witemsTags');
			$reportTags = explode (',', $this->data['tags']);
			foreach ($reportTags as $tagId)
			{
				$tagDef = \E10\searchArray($itemsTags, 'id', $tagId);
				if ($tagDef)
					$tags[] = $tagDef['ndx'];
			}
		}

		if ($comboByCats !== 0)
			$this->createContent_ByCategory ($comboByCats, $subCategories, $tags);
		else
			$this->createContent_Default ();
	}
}
