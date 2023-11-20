<?php

namespace e10pro\purchase\libs\apps;


/**
 * class WidgetPriceList
 */
class WidgetPriceList extends \Shipard\UI\Core\UIWidget
{
  var $units = [];
  var $subCatsNdxs = [];
  var $categories = [];

	function loadData_Categories()
	{
    $comboByCats = intval($this->app()->cfgItem ('options.e10doc-buy.purchItemComboCats', 0));
		if ($comboByCats !== 0)
		{
			$catPath = $this->app()->cfgItem ('e10.witems.categories.list.'.$comboByCats, '---');
			$cats = $this->app()->cfgItem ("e10.witems.categories.tree".$catPath.'.cats');
			forEach ($cats as $catId => $cat)
			{
				$this->categories [$cat['ndx']] = ['id' => 'c'.$cat['ndx'], 'shortName' => $cat['shortName'], 'fullName' => $cat['fullName']];
        $this->subCatsNdxs[] = $cat['ndx'];
			}
		}
	}

	function loadData_Items($catNdx)
	{
		$this->units = $this->app->cfgItem ('e10.witems.units');

    $q = [];
    array_push($q, 'SELECT items.*');
    array_push($q, ' FROM e10_witems_items AS items');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND docState = %i', 4000);

    array_push ($q, ' AND EXISTS (',
                    'SELECT ndx FROM e10_base_doclinks',
                    ' WHERE items.ndx = srcRecId AND srcTableId = %s', 'e10.witems.items',
                    ' AND dstTableId = %s', 'e10.witems.itemcategories',
                    ' AND e10_base_doclinks.dstRecId = %i)', $catNdx
                  );

    /*
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
    */

    array_push ($q, ' ORDER BY items.orderCashRegister, items.fullName');

    $data = [];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      /*
      if ($r['groupCashRegister'] !== '')
      {
        $i = ['name' => $r['groupCashRegister'], 'header' => 1];
        $i['_options'] = ['colSpan' => ['name' => 3]];
        $data [$r['ndx'].'G'] = $i;
				continue;
      }
      */
      $i = [
				'id' => $r['id'],
				'name' => $r['fullName'],
				'unit' => $this->units[$r['defaultUnit']]['shortcut'],
				'item' => 1,
			];

			if (trim($r['description']) !== '')
			{
				$i['description'] = trim($r['description']);
			}

      $i['price'] = $r['priceBuy'];
      $pks[] = $r['ndx'];
      $data [$r['ndx']] = $i;
    }

    $this->getPricesFromProperty($pks, $data);

    $this->categories[$catNdx]['items'] = array_values($data);
  }

  function loadData()
	{
    $this->loadData_Categories();
    foreach ($this->subCatsNdxs as $catNdx)
      $this->loadData_Items($catNdx);

    $this->uiTemplate->data['categories'] = array_values($this->categories);
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
      if (strlen($r['valueString']) > 20)
        $items[$itemNdx]['priceIsText'] = 1;
			$items[$itemNdx]['price'] = $r['valueString'];
			$items[$itemNdx]['priceFromProperty'] = $r['valueString'];
		}
	}

	function renderData()
	{
		$templateStr = $this->uiTemplate->subTemplateStr('modules/e10pro/purchase/libs/apps/subtemplates/priceList');
		$code = $this->uiTemplate->render($templateStr);

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
	}

	public function createContent ()
	{
		$this->loadData();
		$this->renderData();
	}

	public function title()
	{
		return FALSE;
	}
}
