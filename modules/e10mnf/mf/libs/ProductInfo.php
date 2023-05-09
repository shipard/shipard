<?php

namespace e10mnf\mf\libs;

use \Shipard\Base\Utility;
use \Shipard\Utils\Str, \Shipard\Utils\Json, \Shipard\Utils\Utils;
use \Shipard\Utils\World;
use \e10doc\core\libs\E10Utils;


/**
 * class ProductInfo
 */
class ProductInfo extends Utility
{
  var $productNdx = 0;
  var $productRecData = NULL;
  var $productsVariants = [];

  var $data = [];
  var $countVariants = 0;


  public function setProduct($productNdx)
  {
    $this->productNdx = $productNdx;
    $this->productRecData = $this->app()->loadItem($this->productNdx, 'e10mnf.mf.products');
  }

  protected function loadData()
  {
    $this->loadVariants();
    $this->loadMaterials();
  }

  protected function loadVariants()
  {
    $this->data['variants'] = [];

    $q = [];
    array_push($q, 'SELECT [mats].productVariant, ');
    array_push($q, ' [variants].id AS [variantId], [variants].fullName AS [variantFullName]');
    array_push($q, ' FROM [e10mnf_mf_productsMaterials] AS [mats]');
    array_push($q, ' LEFT JOIN [e10mnf_mf_productsVariants] AS [variants] ON [mats].[productVariant] = [variants].[ndx]');
    array_push($q, ' WHERE [mats].[product] = %i', $this->productNdx);
    array_push($q, ' AND [mats].productVariant != %i', 0);
    array_push($q, ' GROUP BY [mats].productVariant');
    array_push($q, ' ORDER BY [variants].id');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $vid = 'V'.$r['productVariant'];
      $item = [
        'id' => $r['variantId'], 'fn' => $r['variantFullName'],
        'tableBOM' => [], 'bomSum' => ['price' => 0.0, 'q' => 0],
      ];

      $this->data['variants'][$vid] = $item;
      $this->countVariants++;
    }

    if (!$this->countVariants)
    {
      $this->data['variants']['V0'] = [
        'id' => '', 'fn' => '',
        'tableBOM' => [], 'bomSum' => ['price' => 0.0, 'q' => 0],
      ];
    }
  }

  protected function loadMaterials()
  {
    $this->data['bom'] = [];
    $this->data['tableBOM'] = [];

    $this->data['headerBOM'] = [
      '#' => '#',
      'item' => 'Položka',
      'pos' => 'Pozice',
      'q' => ' Množství'
    ];

    $this->data['headerBOM']['stockState'] = ' Na skladě';
    $this->data['headerBOM']['priceS'] = ' Cena';

    $q = [];
    array_push($q, 'SELECT [mats].*,');
    array_push($q, ' [witems].fullName AS witemFullName, [witems].shortName AS witemShortName, [witems].id AS witemId,');
    array_push($q, ' [variants].id AS [variantId]');
    array_push($q, ' FROM [e10mnf_mf_productsMaterials] AS [mats]');
    array_push($q, ' LEFT JOIN [e10_witems_items] AS [witems] ON [mats].[item] = [witems].[ndx]');
    array_push($q, ' LEFT JOIN [e10mnf_mf_productsVariants] AS [variants] ON [mats].[productVariant] = [variants].[ndx]');
    array_push($q, ' WHERE [mats].[product] = %i', $this->productNdx);
    array_push($q, ' ORDER BY [rowOrder]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'itemNdx' => $r['item'],
        'itemFN' => $r['witemFullName'],
        'itemSN' => $r['witemShortName'],
        'pos' => $r['positions'],
      ];

      $item['item'] = [
        ['text' => $r['witemShortName'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $r['item'], 'class' => ''],
      ];

      $suppliers = $this->loadItemSuppliers($r['item']);
      foreach ($suppliers as $sl)
      {
        $item['item'][] = $sl;
      }

      $item['item'][] = ['text' => $r['witemId'], 'class' => 'label label-default pull-right'];
      $item['item'][] = ['text' => $r['witemFullName'], 'class' => 'e10-small break'];

      $item['q'] = $r['quantity'];

      $this->itemStockInfo($r['item'], $item);

      if (isset($item ['stockState']) && $item ['stockState'] != 0.0)
        $item['price'] = round(($item ['stockPriceAll'] / $item ['stockState']) * $r['quantity'], 3);
      elseif (isset($item ['buyQuantity']) && $item ['buyQuantity'] !== 0.0)
        $item['price'] = round(($item ['buyPriceAll'] / $item ['buyQuantity']) * $r['quantity'], 3);

      if (isset($item['price']))
        $item['priceS'] = Utils::nf($item['price'], 3);

      if (!$r['productVariant'])
      {
        foreach ($this->data['variants'] as $vid => $variantItem)
        {
          $this->data['variants'][$vid]['tableBOM'][] = $item;
          $this->data['variants'][$vid]['bomSum']['price'] += $item['price'];
          $this->data['variants'][$vid]['bomSum']['q'] += $item['q'];
        }
      }
      else
      {
        $vid = 'V'.$r['productVariant'];
        $this->data['variants'][$vid]['tableBOM'][] = $item;
        $this->data['variants'][$vid]['bomSum']['price'] += $item['price'];
        $this->data['variants'][$vid]['bomSum']['q'] += $item['q'];
      }
    }

    foreach ($this->data['variants'] as $vid => $variantItem)
    {
      $sumRow = [
        'item' => 'CELKEM',
        'q' => $this->data['variants'][$vid]['bomSum']['q'],
        'price' => round($this->data['variants'][$vid]['bomSum']['price'], 2),
        'priceS' => Utils::nf($this->data['variants'][$vid]['bomSum']['price'], 3),
      ];
      $sumRow ['_options'] = ['class' => 'sumtotal', 'beforeSeparator' => 'separator'];

      $this->data['variants'][$vid]['tableBOM'][] = $sumRow;
    }
  }

  protected function itemStockInfo($itemNdx, &$dst)
  {
		$date = utils::today();
		$fiscalYear = e10utils::todayFiscalYear($this->app, $date);

		$q = [];
    array_push ($q, 'SELECT SUM(quantity) as quantity, SUM(price) as price, MAX(date) as lastDate, item, unit ');
		array_push ($q, 'FROM [e10doc_inventory_journal] WHERE [item] = %i', $itemNdx,
		  		          ' AND [fiscalYear] = %i', $fiscalYear /*, ' AND [date] <= %d', $date*/);

		$warehouse = 1;
		if ($warehouse)
			array_push ($q, ' AND [warehouse] = %i', $warehouse);

		array_push ($q, ' GROUP BY item, unit');

		$rows = $this->app()->db()->query ($q);
		forEach ($rows as $r)
    {
			$dst ['stockState'] = $r['quantity'];
      $dst ['stockPriceAll'] = $r['price'];
    }

		$q = [];
    array_push ($q, 'SELECT quantity as quantity, price as price');
		array_push ($q, ' FROM [e10doc_inventory_journal] WHERE [item] = %i', $itemNdx, ' AND moveType = %i', 0);
    array_push ($q, ' ORDER BY [date] DESC');
    array_push ($q, ' LIMIT 1');

		$rows = $this->app()->db()->query ($q);
		forEach ($rows as $r)
    {
			$dst ['buyQuantity'] = $r['quantity'];
      $dst ['buyPriceAll'] = $r['price'];
      break;
    }
  }

  public function loadItemSuppliers($itemNdx)
  {
    $labels = [];
    $q = [];
    array_push($q, 'SELECT itemSuppliers.*, persons.fullName AS personName');
    array_push($q, ' FROM [e10_witems_itemSuppliers] AS itemSuppliers');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON itemSuppliers.supplier = persons.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND itemSuppliers.item = %i', $itemNdx);
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($r['url'] === '')
        continue;
      $l = ['text' => substr ($r['personName'], 0, 3), 'url' => $r['url'], 'class' => 'e10-small pull-right', '_icon' => 'system/iconLink'];
      $l['title'] = $r['personName'].': '.$r['itemId'];

      $labels[] = $l;
    }

    return $labels;
  }

  public function run()
  {
    $this->loadData();
  }
}
