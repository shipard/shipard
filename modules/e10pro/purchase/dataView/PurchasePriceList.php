<?php

namespace e10pro\purchase\dataView;
use \lib\dataView\DataView;
use \Shipard\Utils\TableRenderer;


/**
 * @class PurchasePriceList
 */
class PurchasePriceList extends DataView
{
	var $mainCategory = '';
  var $mainCategoryNdx = 0;
  var $units;

	var $enabledShowAs = ['html', 'json'];

	protected function init()
	{
		parent::init();

		$this->requestParams['showAs'] = strval($this->app()->requestPath(3));
		if ($this->requestParams['showAs'] === '')
			$this->requestParams['showAs'] = 'html';

		if (!in_array($this->requestParams['showAs'], $this->enabledShowAs))
		{
			$this->data['errors'][] = ['msg' => 'Nepodporovaný formát `'.$this->requestParams['showAs'].'`'];
			$this->requestParams['showAs'] = 'html';
			return;
		}

    $this->mainCategory = $this->requestParam('category');
    if ($this->mainCategory !== '')
    {
      $categoryItem = $this->db()->query('SELECT ndx FROM [e10_witems_itemcategories] WHERE [id] = %s', $this->mainCategory)->fetch();
      if ($categoryItem)
        $this->mainCategoryNdx = $categoryItem['ndx'];
    }

		$this->checkRequestParamsList('withLabels');
		$this->checkRequestParamsList('withoutLabels');
	}

	protected function loadData()
	{
		$this->units = $this->app->cfgItem ('e10.witems.units');

    $q = [];
    array_push($q, 'SELECT items.*');
    array_push($q, ' FROM e10_witems_items AS items');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND docState = %i', 4000);

    if ($this->mainCategoryNdx !== 0)
    {
      array_push ($q, ' AND EXISTS (',
                      'SELECT ndx FROM e10_base_doclinks',
                      ' WHERE items.ndx = srcRecId AND srcTableId = %s', 'e10.witems.items',
                      ' AND dstTableId = %s', 'e10.witems.itemcategories',
                      ' AND e10_base_doclinks.dstRecId = %i)', $this->mainCategoryNdx
                    );
    }

		if (isset($this->requestParams['withLabels']) && count($this->requestParams['withLabels']))
		{
			array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE items.ndx = recid AND tableId = %s', 'e10.witems.items',
				' AND [clsfItem] IN %in', $this->requestParams['withLabels'],
				')');
		}
		if (isset($this->requestParams['withoutLabels']) && count($this->requestParams['withoutLabels']))
		{
			array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE items.ndx = recid AND tableId = %s', 'e10.witems.items',
				' AND [clsfItem] IN %in', $this->requestParams['withoutLabels'],
				')');
		}

    array_push ($q, ' ORDER BY items.orderCashRegister, items.fullName');

    $data = [];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($r['groupCashRegister'] !== '')
      {
        $i = ['name' => $r['groupCashRegister'], 'header' => 1];
        $i['_options'] = ['colSpan' => ['name' => 3]];
        $data [$r['ndx'].'G'] = $i;
				continue;
      }
      $i = [
				'id' => $r['id'],
				'name' => $r['fullName'],
				'unit' => $this->units[$r['defaultUnit']]['shortcut'],
				'item' => 1,
			];

			if ($r['description'] !== '')
			{
				$i['name'] = [
					['text' => $r['fullName'], 'class' => 'block'],
					['text' => $r['description'], 'class' => 'itemDecription'],
				];
			}

      $i['_options'] = ['cellClasses' => ['unit' => 'unit']];

      $i['price'] = $r['priceBuy'];
      $pks[] = $r['ndx'];
      $data [$r['ndx']] = $i;
    }

    $this->getPricesFromProperty($pks, $data);

    $this->data['table'] = $data;
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

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'html')
    	return $this->renderDataAsHtml();
		if ($showAs === 'json')
    	return $this->renderDataAsJson();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsHtml()
	{
		$c = '';

    $h = ['id' => 'ID', 'name' => 'Název', 'price' => ' Cena', 'unit' => 'Jedn.'];

    $tr = new TableRenderer($this->data['table'], $h, ['tableClass' => 'purchasePriceList'], $this->app());
    $c .= $tr->render();

		return $c;
	}

	protected function renderDataAsJson()
	{
	}
}
