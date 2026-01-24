<?php

namespace e10doc\purchase\libs;
use \lib\dataView\DataView, \Shipard\Utils\Utils, \Shipard\Utils\Json;
use \e10doc\core\libs\E10Utils;


/**
 * class PurchaseInfo
 */
class PurchaseInfo extends DataView
{
  var $dateLimit = NULL;
	var $units;
	var $items;
  var $dstUnit = 'kg';
  var $maxDays = 4;
  var $maxBrands = 10;

	protected function init()
	{
		parent::init();

    $this->maxDays = intval($this->requestParam('maxDays', $this->maxDays));
    $this->maxBrands = intval($this->requestParam('maxBrands', $this->maxBrands));
    $this->dstUnit = strval($this->requestParam('dstUnit', $this->dstUnit));

    $this->units = $this->app->cfgItem ('e10.witems.units');
    $this->dateLimit = new \DateTime('-15 days');
	}

	protected function loadData()
	{
		$q = [];
		array_push ($q, 'SELECT heads.[dateAccounting], items.brand, [rows].unit, brands.shortName as itemBrandName,');
		array_push ($q, ' SUM([rows].quantity) as quantity, SUM([rows].taxBaseHc) AS taxBaseHc ');
    array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND heads.[docState] = %i', 4000);
    array_push ($q, ' AND heads.[docType] = %s', 'purchase');
		array_push ($q, ' AND [rows].rowType = %i', 0);
		array_push ($q, ' AND heads.[dateAccounting] >= %d', $this->dateLimit);
		array_push ($q, ' GROUP BY heads.[dateAccounting], items.brand, [rows].unit');
		array_push ($q, ' ORDER BY heads.[dateAccounting] DESC, items.brand');

		$data = [];
    $dates = [];
    $brands = [];
		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
      if ($r['unit'] !== 'kg' && $r['unit'] !== 't' && $r['unit'] !== 'g')
        continue;
      $dateId = $r['dateAccounting']->format('Y-m-d');
      $brandId = 'B'.$r['brand'];
      $quantity = $this->quantity($r['quantity'], $r['unit'], $this->dstUnit);
			$item = [
          'dateAccounting' => $dateId,
					'title' => $r['itemBrandName'],
					'quantity' => intval(round($quantity)),
          'taxBaseHc' => intval(round($r['taxBaseHc'])),
					'unit' => $this->dstUnit,//$this->units[$this->dstUnit]['shortcut']
			];
			$data['byDate'][$dateId][] = $item;
      $data['byBrand'][$brandId][$dateId] = $item;

      if (!in_array($dateId, $dates))
        $dates[] = $dateId;

      if (!in_array($brandId, $brands))
        $brands[$brandId] = ['sn' => $r['itemBrandName']];
		}

    while (count($dates) > $this->maxDays)
      array_pop($dates);

    $table = [];
    $header = ['title' => 'Značka'];

    $header2 = [
      ['title' => 'Značka', '_options' => ['rowSpan' => ['title' => 2]]],
      ['title' => '', '_options' => []]
    ];

    $valuesCols = [];
    foreach ($dates as $dateId)
    {
      $header[$dateId.'-quantity'] = '+'.Utils::datef($dateId, '%n %k').' ['.$this->dstUnit.']';
      $header[$dateId.'-taxBaseHc'] = '+'.Utils::datef($dateId, '%n %k').' - [Kč]';
      $valuesCols[] = $dateId.'-quantity';
      $valuesCols[] = $dateId.'-taxBaseHc';

      $header2[0][$dateId.'-quantity'] = Utils::datef($dateId, '%n %k');
      $header2[0]['_options']['colSpan'][$dateId.'-quantity'] = 2;
      $header2[0]['_options']['cellClasses'][$dateId.'-quantity'] = 'center';

      $header2[1][$dateId.'-quantity'] = $this->dstUnit;
      $header2[1][$dateId.'-taxBaseHc'] = 'Kč';
      $header2[1]['_options']['cellClasses'][$dateId.'-quantity'] = 'center';
    }

    foreach ($brands as $brandId => $brandInfo)
    {
      if (!isset($table[$brandId]))
      {
        $row = [
          'title' => $brandInfo['sn'] ?? '---',
        ];

        foreach ($dates as $dateId)
        {
          $row[$dateId.'-quantity'] = $data['byBrand'][$brandId][$dateId]['quantity'] ?? 0;
          $row[$dateId.'-taxBaseHc'] = $data['byBrand'][$brandId][$dateId]['taxBaseHc'] ?? 0;
        }

        $table[$brandId] = $row;
      }
    }

		$tableShort = [];
		$cutedSum = [];
		$maxRows = $this->maxBrands;
		Utils::cutRows ($table, $tableShort, $valuesCols, $cutedSum, $maxRows);
		if (count($cutedSum))
		{
			$csRow = ['title' => 'Ostatní'];
      foreach ($valuesCols as $vc)
			  $csRow[$vc] = intval($cutedSum[$vc]);
			$tableShort['BSUM'] = $csRow;
		}

    $this->data['tableShort'] = $tableShort;
    $this->data['table'] = $table;
    $this->data['header'] = $header;
    $this->data['header2'] = $header2;

    $params = ['header' => $header2];

    $tr = new \Shipard\Utils\TableRenderer($tableShort, $header, $params, $this->app());
		$tableHtmlCode = $tr->render();
    $this->data['tableHtmlCodeShort'] = $tableHtmlCode;


    $tr = new \Shipard\Utils\TableRenderer($table, $header, $params, $this->app());
		$tableHtmlCode = $tr->render();
    $this->data['tableHtmlCode'] = $tableHtmlCode;



    $this->data['purchasesStates'] = $data;
    $this->data['dateLimit'] = $this->dateLimit->format('Y-m-d');
	}

	protected function renderDataAs($showAs)
	{
    if ($showAs === 'html')
      return $this->data['tableHtmlCode'];

    return $this->renderDataAsJson();
	}

	protected function quantity ($quantity, $srcUnit, $dstUnit)
	{
    $ucc = E10Utils::unitsConversionCoefficient($this->app(), $srcUnit, $dstUnit);
		return round($quantity * $ucc, 3);
	}

	protected function renderDataAsJson()
	{
		$c = '';
    $c .= Json::lint($this->data);
		return $c;
	}
}
