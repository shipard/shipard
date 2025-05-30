<?php

namespace e10pro\loyp\libs;
use \Shipard\Utils\Utils;


/**
 * class DocsPointsDetailContent
 */
class DocsPointsOutDetailContent extends \Shipard\Base\Utility
{
  var ?array $docRecData = NULL;
  var $content = NULL;


  public function setDocument(array $docRecData)
  {
    $this->docRecData = $docRecData;
  }

  public function createContent()
  {
		$q = [];
		array_push($q, 'SELECT [rows].text AS rText, [rows].quantity AS rQuantity, [rows].unit AS rUnit, [rows].priceItem AS rPriceItem,');
		array_push($q, ' [rows].priceAll AS rPriceAll, [rows].item, [rows].ownerRowMain, [rows].ownerRow AS rowOwnerRow,');
		array_push($q, ' [rows].invPrice AS rInvPrice, [rows].invPriceAcc AS rInvPriceAcc, [rows].operation AS rOperation, [rows].itemIsLoyp AS rItemIsLoyp,');
		array_push($q, ' [rows].lpPriceItem AS rlpPriceItem, [rows].lpPriceAll AS rlpPriceAll,');
		array_push($q, ' [items].[id] AS [itemId]');
		array_push($q, ' FROM [e10doc_core_rows] AS [rows] ');
		array_push($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [rows].[item] = [items].[ndx]');
		array_push($q, ' WHERE [rows].document = %i', $this->docRecData ['ndx']);
		array_push($q, ' AND [rowType] = %i', 0);
    array_push($q, ' AND [rows].lpPriceItem != %i', 0);
		array_push($q, ' ORDER BY [rows].rowOrder, [rows].ndx');

		$cfgUnits = $this->app->cfgItem ('e10.witems.units');
		$rows = $this->db()->query($q);
		$list = [];
		$totalPriceAll = 0.0;
		forEach ($rows as $r)
		{
			$unit = (isset($cfgUnits[$r['rUnit']])) ? $cfgUnits[$r['rUnit']]['shortcut'] : '';
			$rowItem = [
				'text' => [['text' => $r['rText'], 'class' => 'block']],
				'item' => ['text' => $r['itemId'], 'docAction' => 'edit', 'pk' => $r['item'], 'table' => 'e10.witems.items'],
				'quantity' => $r['rQuantity'],
				'unit' => $unit,
				'priceItem' => $r['rPriceItem'],
				'priceAll' => $r['rPriceAll'],
        'lpPriceItem' => $r['rlpPriceItem'],
        'lpPriceAll' => $r['rlpPriceAll'],
			];

			$list[] = $rowItem;
			$totalPriceAll += $rowItem['priceAll'];
		}

    if (count ($list))
    {
      $h = [
        '#' => '#',
        'item' => 'Pol.',
        'text' => 'Text řádku',
        'quantity' => ' Množství',
        'unit' => 'Jedn.',
        'priceItem' => ' Cena/Jedn.',
        'priceAll' => '+Cena celkem',
        'lpPriceItem' => ' Body/Jedn.',
        'lpPriceAll' => '+Body celkem',
      ];

      $this->content = [
        'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
        'title' => ['icon' => 'tables/e10pro.loyp.loyps', 'text' => 'Uplatněné body'],
        'header' => $h, 'table' => $list,
      ];
    }
  }
}

