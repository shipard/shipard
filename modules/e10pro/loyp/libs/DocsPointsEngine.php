<?php

namespace e10pro\loyp\libs;
use \Shipard\Utils\Utils;


/**
 * class DocsPointsEngine
 */
class DocsPointsEngine extends \Shipard\Base\Utility
{
  var $documentNdx = 0;
  var $documentRecData = 0;
  var $items = [];
  var $sumsByCats = [];
  var $pointsSettings = [];
  var $totalPts = 0;

  var $doSave = 0;

  var $calcExplain = [];
  var $docDetailContent = NULL;

  protected function loadItems()
  {
    // -- points settings
    $q = [];
    array_push ($q, 'SELECT [points].*, [cats].fullName AS categoryName');
    array_push ($q, ' FROM [e10pro_loyp_pointsSettings] AS [points]');
    array_push ($q, ' LEFT JOIN [e10_witems_itemcategories] AS [cats] ON [points].witemCategory = [cats].ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [points].docState IN %in', [4000, 8000]);
		array_push ($q, ' AND ([points].[validFrom] IS NULL', ' OR [points].[validFrom] <= %d)', $this->documentRecData['dateAccounting']);
		array_push ($q, ' AND ([points].[validTo] IS NULL', ' OR [points].[validTo] >= %d)', $this->documentRecData['dateAccounting']);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $catNdx = $r['witemCategory'];
      $this->pointsSettings[$catNdx] = $r->toArray();
      if (!$this->pointsSettings[$catNdx]['categoryName'])
        $this->pointsSettings[$catNdx]['categoryName'] = 'Ostatní';
    }

    // -- items
    $q = [];
    array_push($q, 'SELECT [rows].*');
    array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
    array_push($q, '');
    array_push($q, ' WHERE [document] = %i', $this->documentNdx);
    array_push($q, ' AND [rowType] = %i', 0);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $itemNdx = $r['item'];

      if (!isset($this->items[$itemNdx]))
        $this->items[$itemNdx] = [
          'itemNdx' => $itemNdx,
          'price' => $r['taxBaseHc'],
          'cats' => [],
        ];
      else
        $this->items[$itemNdx]['price'] += $r['taxBaseHc'];
    }

    // -- categories
    $q = [];
		array_push($q, 'SELECT * FROM [e10_base_doclinks]');
    array_push($q, ' WHERE linkId = %s', 'e10-witems-items-categories');
		array_push($q, ' AND srcTableId = %s', 'e10.witems.items');
    //array_push($q, ' AND srcTableId = %s', 'e10.witems.items');
		array_push($q, ' AND srcRecId IN %in', array_keys($this->items));
		$rows = $this->db()->query ($q);
    foreach ($rows as $r)
    {
      $itemNdx = $r['srcRecId'];
      $this->items[$itemNdx]['cats'][] = $r['dstRecId'];
    }

    // -- sums by cats
    foreach ($this->items as $itemNdx => $itemInfo)
    {
      $catNdx = $itemInfo['cats'][0] ?? 0;
      if (!isset($this->pointsSettings[$catNdx]))
        $catNdx = 0;
      if (!isset($this->pointsSettings[$catNdx]))
        continue;
      if (!isset($this->sumsByCats[$catNdx]))
        $this->sumsByCats[$catNdx] = ['catNdx' => $catNdx, 'price' => round($itemInfo['price'], 2)];
      else
        $this->sumsByCats[$catNdx]['price'] += round($itemInfo['price'], 2);
    }
  }

  protected function createPoints()
  {
    $this->totalPts = 0;

    $this->calcExplain['sbc'] = $this->sumsByCats;
    $this->calcExplain['items'] = $this->items;
    $this->calcExplain['totalPts'] = $this->totalPts;

    foreach ($this->sumsByCats as $catNdx => $catSum)
    {
      $stepLabel = [];
      $pts = $this->calcPoints($this->pointsSettings[$catNdx], $catSum['price'], $stepLabel);
      $this->totalPts += $pts;
      $this->calcExplain['stepsLabels'][] = $stepLabel;
      $this->calcExplain['stepsInfo'][] = [
        'catNdx' => 0, 'ps' => $this->pointsSettings[$catNdx], 'price' => $catSum['price'], 'pts' => $pts,
        'mathExplain' => $stepLabel,
      ];
    }

    if ($this->doSave)
    {
      $this->db()->query('DELETE FROM [e10pro_loyp_pointsJournal] WHERE [document] = %i', $this->documentNdx);
      // -- add to journal
      $journalItem = [
        'rowType' => 1,

        'document' => $this->documentNdx,
        'person' => $this->documentRecData['person'],
        'cntPoints' => $this->totalPts,
      ];

      $this->db()->query('INSERT INTO [e10pro_loyp_pointsJournal] ', $journalItem);
    }
  }

  protected function calcPoints($pointsSettings, $price, &$stepLabel)
  {
    $pts = 0;
    if (!$pointsSettings['perAmount'])
      return 0;

    $cntBlocks = intval(floatval($price / $pointsSettings['perAmount']));
    $pts = $cntBlocks * $pointsSettings['cntPoints'];

    $stepMath = Utils::nf($price, 2).' / '.Utils::nf($pointsSettings['perAmount'], 2).' = '.$cntBlocks.' * '.Utils::nf($pointsSettings['cntPoints']).' ▶︎ '.Utils::nf($pts);
    $stepLabel['text'] = $stepMath;
    $stepLabel['prefix'] = $pointsSettings['categoryName'];
    $stepLabel['class'] = 'block';

    return $pts;
  }

  public function createDocDetailContent()
  {
    $t = [];
    foreach ($this->calcExplain['stepsInfo'] as $stepInfo)
    {
      $me = $stepInfo['mathExplain'];
      unset($me['prefix']);
      $item =[
        'title' => $stepInfo['ps']['categoryName'],
        'price' => $stepInfo['price'],
        'pts' => $stepInfo['pts'],
        'mathExplain' => $me,
      ];

      $t[] = $item;
    }

    $paneTitle = [
      ['text' => 'Věrnostní body', 'class' => 'h2'],
      ['text' => Utils::nf($this->totalPts, 0), 'class' => 'h2 pull-right'],
    ];

    $h = [
      '#' => '#', 'title' => 'Kategorie', 'price' => '+Cena', 'pts' => '+Body', 'mathExplain' => 'Výpočet'
    ];

    $this->docDetailContent = ['table' => $t, 'header' => $h, 'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $paneTitle];
  }

  public function doDocument($docRecData, $doSave = 0)
  {
    $this->doSave = $doSave;
    $this->documentNdx = $docRecData['ndx'];
    $this->documentRecData = $docRecData;
    $this->loadItems();
    $this->createPoints();
  }
}
