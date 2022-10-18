<?php

namespace e10doc\purchase\libs;
use Shipard\Base\Utility;
use \Shipard\Utils\Json;
use \Shipard\Utils\Utils;


class CalcVDSMultiItemRow extends Utility
{
  public function checkBeforeSave (&$recData, $ownerData, $sci)
  {
    if (!isset($recData['rowData']))
    {
      $rowData = [];
      foreach ($sci['columns'] as $col)
			{
        if (isset($col['defaultValue']) && !isset($rowData[$col['id']]))
          $rowData[$col['id']] = $col['defaultValue'];
      }
    }
    else
      $rowData = Json::decode($recData['rowData']);

    if (!$rowData)
      return;

    $priceTotal = 0.0;
    for ($colNdx = 1; $colNdx < 99; $colNdx++)
    {
      $colIDBase = 'item-'.$colNdx.'-';
      $colIDItemNdx = $colIDBase.'ndx';
      $colIDItemPercents = $colIDBase.'percents';
      $colIDItemQuantity = $colIDBase.'quantity';
      $colIDItemPriceUnit = $colIDBase.'price-unit';
      $colIDItemPriceAll = $colIDBase.'price-all';

      $colDef = Utils::searchArray($sci['columns'], 'id', $colIDItemNdx);
      if (!$colDef)
        break;

      $itemQuantity = 0.0;
      $itemQuantity = $recData['quantity'] * 1000 * $rowData[$colIDItemPercents] / 100;
      $rowData[$colIDItemQuantity] = round($itemQuantity, 3);
      $itemPriceAll = round($rowData[$colIDItemPriceUnit] * $itemQuantity, 2);
      $rowData[$colIDItemPriceAll] = $itemPriceAll;

      $priceTotal = round($priceTotal + $itemPriceAll, 2);
    }

    $recData ['priceSource'] = 1;
    $recData ['priceAll'] = $priceTotal;


    $recData['rowData'] = Json::lint($rowData);
  }
}

