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
  var $sumsByItems = [];
  var $sumsByCats = [];
  var $sumsOthers = [];
  var $pointsSettings = [];
  var $totalPts = 0;

  var $doSave = 0;

  var $calcExplain = [];
  var $docDetailContent = NULL;

  var $loypCfg = NULL;

  protected function loadItems()
  {
    if (!$this->documentRecData['loyp'])
      return;

    // -- points settings
    $q = [];
    array_push ($q, 'SELECT [points].*');
    array_push ($q, ' FROM [e10pro_loyp_pointsSettings] AS [points]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [points].docState IN %in', [4000, 8000]);
		array_push ($q, ' AND ([points].[validFrom] IS NULL', ' OR [points].[validFrom] <= %d)', $this->documentRecData['dateAccounting']);
		array_push ($q, ' AND ([points].[validTo] IS NULL', ' OR [points].[validTo] >= %d)', $this->documentRecData['dateAccounting']);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($r['settingsType'] === 0)
      { // item
        $itemNdx = $r['item'];
        $this->pointsSettings[0][$itemNdx] = $r->toArray();
      }
      elseif ($r['settingsType'] === 1)
      { // category
        $catNdx = $r['witemCategory'];
        $this->pointsSettings[1][$catNdx] = $r->toArray();
      }
      elseif ($r['settingsType'] === 2)
      { // ALL
        $this->pointsSettings[2][0] = $r->toArray();
      }
    }

    // -- items
    $q = [];
    array_push($q, 'SELECT [rows].*');
    array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
    array_push($q, ' WHERE [document] = %i', $this->documentNdx);
    array_push($q, ' AND [rowType] = %i', 0);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $itemNdx = $r['item'];

      if (!isset($this->items[$itemNdx]))
      {
        $this->items[$itemNdx] = [
          'itemNdx' => $itemNdx,
          'price' => $r['taxBaseHc'],
          'quantity' => $r['quantity'],
          'cats' => [],
        ];
      }
      else
      {
        $this->items[$itemNdx]['price'] += $r['taxBaseHc'];
        $this->items[$itemNdx]['quantity'] += $r['quantity'];
      }
    }

    // -- categories
    $q = [];
		array_push($q, 'SELECT * FROM [e10_base_doclinks]');
    array_push($q, ' WHERE linkId = %s', 'e10-witems-items-categories');
		array_push($q, ' AND srcTableId = %s', 'e10.witems.items');
		array_push($q, ' AND srcRecId IN %in', array_keys($this->items));
		$rows = $this->db()->query ($q);
    foreach ($rows as $r)
    {
      $itemNdx = $r['srcRecId'];
      $this->items[$itemNdx]['cats'][] = $r['dstRecId'];
    }

    // -- sums by items
    foreach ($this->items as $itemNdx => $itemInfo)
    {
      if (!isset($this->pointsSettings[0][$itemNdx]))
        continue;
      if (!isset($this->sumsByItems[$itemNdx]))
        $this->sumsByItems[$itemNdx] = ['price' => round($itemInfo['price'], 2), 'quantity' => round($itemInfo['quantity'], 3)];
      else
      {
        $this->sumsByItems[$itemNdx]['price'] += round($itemInfo['price'], 2);
        $this->sumsByItems[$itemNdx]['quantity'] += round($itemInfo['quantity'], 3);
      }
      $this->items[$itemNdx]['used'] = 1;
    }


    // -- sums by cats
    foreach ($this->items as $itemNdx => $itemInfo)
    {
      if (isset($this->items[$itemNdx]['used']))
        continue;

      $catNdx = $itemInfo['cats'][0] ?? 0;
      if (!isset($this->pointsSettings[1][$catNdx]))
        continue;

      if (!isset($this->sumsByCats[$catNdx]))
        $this->sumsByCats[$catNdx] = ['price' => round($itemInfo['price'], 2), 'quantity' => round($itemInfo['quantity'], 3)];
      else
      {
        $this->sumsByCats[$catNdx]['price'] += round($itemInfo['price'], 2);
        $this->sumsByCats[$catNdx]['quantity'] += round($itemInfo['quantity'], 3);
      }
      $this->items[$itemNdx]['used'] = 1;
    }

    // -- sums others
    foreach ($this->items as $itemNdx => $itemInfo)
    {
      if (isset($this->items[$itemNdx]['used']))
        continue;

      if (!isset($this->pointsSettings[2][0]))
        continue;

      if (!isset($this->sumsOthers[0]))
        $this->sumsOthers[0] = ['price' => round($itemInfo['price'], 2), 'quantity' => round($itemInfo['quantity'], 3)];
      else
      {
        $this->sumsOthers[0]['price'] += round($itemInfo['price'], 2);
        $this->sumsOthers[0]['quantity'] += round($itemInfo['quantity'], 3);
      }
      $this->items[$itemNdx]['used'] = 1;
    }
  }

  protected function createPoints()
  {
    $this->totalPts = 0;

    $this->calcExplain['sbc'] = $this->sumsByCats;
    $this->calcExplain['items'] = $this->items;

    // -- items
    foreach ($this->sumsByItems as $itemNdx => $catSum)
    {
      $stepLabel = [];
      $pts = $this->calcPoints($this->pointsSettings[0][$itemNdx], $catSum, $stepLabel);
      $this->totalPts += $pts;
      $this->calcExplain['stepsLabels'][] = $stepLabel;
      $this->calcExplain['stepsInfo'][] = [
        'itemNdx' => $itemNdx,
        'ps' => $this->pointsSettings[0][$itemNdx],
        'price' => $catSum['price'],
        'quantity' => $catSum['quantity'],
        'pts' => $pts,
        'mathExplain' => $stepLabel,
      ];
    }

    // -- cats
    foreach ($this->sumsByCats as $catNdx => $catSum)
    {
      $stepLabel = [];
      $pts = $this->calcPoints($this->pointsSettings[1][$catNdx], $catSum, $stepLabel);
      $this->totalPts += $pts;
      $this->calcExplain['stepsLabels'][] = $stepLabel;
      $this->calcExplain['stepsInfo'][] = [
        'catNdx' => $catNdx,
        'ps' => $this->pointsSettings[1][$catNdx],
        'price' => $catSum['price'],
        'quantity' => $catSum['quantity'],
        'pts' => $pts,
        'mathExplain' => $stepLabel,
      ];
    }

    // -- others
    foreach ($this->sumsOthers as $otherNdx => $catSum)
    {
      $stepLabel = [];
      $pts = $this->calcPoints($this->pointsSettings[2][$otherNdx], $catSum, $stepLabel);
      $this->totalPts += $pts;
      $this->calcExplain['stepsLabels'][] = $stepLabel;
      $this->calcExplain['stepsInfo'][] = [
        //'catNdx' => $catNdx,
        'ps' => $this->pointsSettings[2][$otherNdx],
        'price' => $catSum['price'],
        'quantity' => $catSum['quantity'],
        'pts' => $pts,
        'mathExplain' => $stepLabel,
      ];
    }

    // -- minimal
    if ($this->totalPts < $this->loypCfg['minPointsPerDoc'])
    {
      $pts = $this->loypCfg['minPointsPerDoc'] - $this->totalPts;
      $stepLabel = ['text' => 'Minimální počet bodů: '.Utils::nf($this->loypCfg['minPointsPerDoc']), 'prefix' => 'Ostatní'];
      $this->calcExplain['stepsLabels'][] = $stepLabel;
      $this->calcExplain['stepsInfo'][] = [
        'catNdx' => 0, 'ps' => ['categoryName' => 'Ostatní'], 'price' => 0, 'pts' => $pts,
        'mathExplain' => $stepLabel,
      ];

      $this->totalPts += $pts;
    }

    if ($this->doSave)
    {
      $this->db()->query('DELETE FROM [e10pro_loyp_pointsJournal] WHERE [document] = %i', $this->documentNdx);
      if (!$this->documentRecData['loyp'])
        return;

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

  protected function calcPoints($pointsSettings, $sum, &$stepLabel)
  {
    $valueForPoints = 0.0;
    $srcForPoints = 0.0;

    if ($this->loypCfg['pointsSource'] == 0)
    { // price
      $valueForPoints = $pointsSettings['perAmount'] ?? 0;
      $srcForPoints = $sum['price'];
    }
    elseif ($this->loypCfg['pointsSource'] == 1)
    { // quantity
      $valueForPoints = $pointsSettings['perQuantity'] ?? 0;
      $srcForPoints = $sum['quantity'];
    }

    $pts = 0.0;
    if (!$valueForPoints)
      return 0;

    $cntBlocks = intval(floatval($srcForPoints / $valueForPoints));
    $pts = round($cntBlocks * $pointsSettings['cntPoints'], 2);

    $stepMath = Utils::nf($srcForPoints, 2).' / '.Utils::nf($valueForPoints, 2).' = '.$cntBlocks.' * '.Utils::nf($pointsSettings['cntPoints'], 2).' ▶︎ '.Utils::nf($pts, 2);
    $stepLabel['text'] = $stepMath;
    $stepLabel['prefix'] = $pointsSettings['fullName'];
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
        'title' => $stepInfo['ps']['fullName'],
        'price' => $stepInfo['price'],
        'quantity' => $stepInfo['quantity'],
        'pts' => $stepInfo['pts'],
        'mathExplain' => $me,
      ];

      $t[] = $item;
    }

    $paneTitle = [
      ['text' => 'Věrnostní body', 'class' => 'h2'],
      ['text' => Utils::nf($this->totalPts, 2), 'class' => 'h2 pull-right'],
    ];

    $h = [
      '#' => '#', 'title' => 'Za co', 'price' => '+Cena', 'quantity' => '+Množství', 'pts' => '+Body', 'mathExplain' => 'Výpočet'
    ];

    $this->docDetailContent = ['table' => $t, 'header' => $h, 'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $paneTitle];
  }

  protected function checkLoyp(&$docRecData)
  {
    $loyps = $this->app()->cfgItem('e10pro.loyp.loyps', NULL);
    if (!$loyps)
    {
      //error_log("__NO LOYP__");
      return;
    }

    $docDate = $docRecData['dateAccounting']->format('Y-m-d');
    $this->loypCfg = NULL;
    $loypNdx = 0;

    foreach ($loyps as $loyp)
    {
      if (isset($loyp['validFrom']) && $docDate < $loyp['validFrom'])
        continue;
      if (isset($loyp['validTo']) && $docDate > $loyp['validTo'])
        continue;

      if ($docRecData['docType'] === 'purchase' && $loyp['type'] !== 1)
        continue;

      $this->loypCfg = $loyp;
      $loypNdx = $loyp['ndx'];
      break;
    }

    if ($docRecData['loyp'] != $loypNdx)
    {
      $docRecData['loyp'] = $loypNdx;
      $this->db()->query ('UPDATE [e10doc_core_heads] SET loyp = %i', $this->loypCfg['ndx'], ' WHERE ndx = %i', $docRecData['ndx']);
    }
  }

  public function doDocument(&$docRecData, $doSave = 0)
  {
    $this->checkLoyp($docRecData);

    $this->doSave = $doSave;
    $this->documentNdx = $docRecData['ndx'];
    $this->documentRecData = $docRecData;

    $this->loadItems();
    $this->createPoints();
  }
}
