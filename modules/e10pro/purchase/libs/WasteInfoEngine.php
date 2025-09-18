<?php

namespace e10pro\purchase\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \e10doc\core\libs\E10Utils;


/**
 * Class WasteInfoEngine
 */
class WasteInfoEngine extends Utility
{
  var $docNdx = 0;
  var $docRecData = NULL;
  var $directionIn = 1; // 1-in, 0-out
  var $directionOut = 0; // 1-out, 0-in

  var $wasteCodes = [];

  var $wasteFromToPeriodBegin = NULL;
  var $wasteFromToPeriodEnd = NULL;

  public function setDocument($docNdx)
  {
    $this->docNdx = $docNdx;
    $this->docRecData = $this->app()->loadItem($this->docNdx, 'e10doc.core.heads');

    if ($this->docRecData['docType'] === 'invno' || $this->docRecData['docType'] === 'stockout')
    { // invoiceOut / stockOut
      $this->directionIn = 0;
      $this->directionOut = 1;
    }

    $this->wasteFromToPeriodBegin = Utils::createDateTime($this->docRecData['dateAccounting']->format('Y').'-01-01');
    $this->wasteFromToPeriodEnd = $this->docRecData['dateAccounting'];
  }

  public function loadData()
  {
    $this->loadData_wasteCodes($this->docRecData);
  }

  protected function loadData_wasteCodes($docRecData)
  {
    $wasteHandlingCodes = $this->app->cfgItem('e10doc.waster.handlingCodes', []);

    $q = [];
    array_push($q, 'SELECT [wasteRows].*, nomencItems.fullName, nomencItems.itemId');
    array_push($q, ' FROM [e10pro_reports_waste_cz_returnRows] AS [wasteRows]');
    array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [wasteRows].wasteCodeNomenc = nomencItems.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [wasteRows].document = %i', $docRecData['ndx']);
    if ($this->directionOut)
      array_push($q, ' AND [wasteRows].dir = %i', 1); // out
    elseif ($this->directionIn)
      array_push($q, ' AND [wasteRows].dir = %i', 0); // in
    array_push($q, ' AND [wasteRows].rowSource = %i', 0);
    array_push($q, ' ORDER BY [wasteRows].[ndx]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $whcCfg = $wasteHandlingCodes[$r['wasteHandlingCode']] ?? NULL;
      if (!$whcCfg)
        continue;
      if ($this->directionIn && $whcCfg['dir'] != 0) // in
        continue;

      $quantity = $r['quantityKG'];
      $wc = 'W'.$r['wasteCodeText'];

      if (!isset($this->wasteCodes[$wc]))
      {
        $this->wasteCodes[$wc] = [
          'wc' => $r['itemId'],
          'fullName' => $r['fullName'],
          'count' => 0,
          'quantity' => 0.0,
          'wasteNotes' => [],
        ];
      }

      $this->wasteCodes[$wc]['count']++;
      $this->wasteCodes[$wc]['quantity'] += $quantity;
    }

    foreach ($this->wasteCodes as $wcId => &$wcData)
    {
      $wcData['quantityPrint'] = Utils::nf($wcData['quantity'], 3);
    }

    $this->loadData_specialities($docRecData);
  }

  protected function loadData_specialities($docRecData)
  {
    foreach ($this->wasteCodes as $wcId => &$wcData)
    {
      $wc = $wcData['wc'];
      if (substr($wc, 0, 2) !== '19')
        continue;

      $info = $this->loadData_WasteFromToInfo($wc, '20', $wcData['quantity']);
      if ($info)
        $wcData['moveFrom20To19'] = $info;

      $info = $this->loadData_WasteFromToInfo($wc, '1501', $wcData['quantity']);
      if ($info)
        $wcData['moveFrom1501To19'] = $info;

      $info = $this->loadData_WasteFromToInfo($wc, '17', $wcData['quantity']);
      if ($info)
        $wcData['moveFrom17To19'] = $info;
    }
  }

  protected function loadData_WasteFromToInfo($wcTo, $wcFromMask, $wcQuantity)
  {
    // -- MOVE
    $q = [];
    array_push($q, 'SELECT SUM(quantityKG) AS quantitySumKG');
    array_push($q, ' FROM [e10pro_reports_waste_cz_returnRows] AS [wasteRows]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [wasteCodeText] LIKE %s', $wcFromMask.'%');
    array_push($q, ' AND [wasteCodeTextMove] = %s', $wcTo);
    array_push($q, ' AND [dateAccounting] >= %d', $this->wasteFromToPeriodBegin);
    array_push($q, ' AND [dateAccounting] <= %d', $this->wasteFromToPeriodEnd);
    array_push($q, ' AND [dir] = %i', 0); // IN

    $this->wasteFromToPeriodBegin = Utils::createDateTime($this->docRecData['dateAccounting']->format('Y').'-01-01');
    $this->wasteFromToPeriodEnd = $this->docRecData['dateAccounting'];

    $sumRecMoveFrom = $this->db()->query($q)->fetch();
    if (!$sumRecMoveFrom)
      return NULL;

    // -- IN TOTAL
    $q = [];
    array_push($q, 'SELECT SUM(quantityKG) AS quantitySumKG');
    array_push($q, ' FROM [e10pro_reports_waste_cz_returnRows] AS [wasteRows]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [wasteCodeText] = %s', $wcTo);
    array_push($q, ' AND [dateAccounting] >= %d', $this->wasteFromToPeriodBegin);
    array_push($q, ' AND [dateAccounting] <= %d', $this->wasteFromToPeriodEnd);
    array_push($q, ' AND [dir] = %i', 0); // IN

    $sumRecTotalSum = $this->db()->query($q)->fetch();
    if (!$sumRecTotalSum)
      return NULL;

    // -- INFO
    $info = [
      'periodBegin' => $this->wasteFromToPeriodBegin->format('d.m.Y'),
      'periodEnd' => $this->wasteFromToPeriodEnd->format('d.m.Y'),
      'moveFromMask' => $wcFromMask,
      'movedQuantity' => round($sumRecMoveFrom['quantitySumKG'], 3),
      'totalQuantity' => round($sumRecTotalSum['quantitySumKG'], 3),
      'ratio' => ($sumRecTotalSum['quantitySumKG'] != 0.0) ? round($sumRecMoveFrom['quantitySumKG'] / $sumRecTotalSum['quantitySumKG'], 6) : 0.0,
    ];
    $calcFormula = $info['movedQuantity'] . ' / '.$info['totalQuantity'] . ' = ' . $info['ratio'];
    $info['calcFormula'] = $calcFormula;

    $info['ratioInPercents'] = round($info['ratio'] * 100.0, 1);
    $info['thisMovedQuantity'] = round($info['ratio'] * $wcQuantity, 3);

    return $info;
  }

	public function loadWasteReportInfo(&$reportData)
	{
		$wnn = [];
		foreach ($reportData['rows'] as $r)
		{
			if (isset($r['rowItemCodes']))
			{
				foreach ($r['rowItemCodes'] as $ic)
				{
					$wasteCode = $ic['itemCodeText'] ?? '';
					if ($wasteCode === '')
						continue;
					$wasteCodeId = 'W'.$wasteCode;
					if (!isset($reportData['infoWasteCodes'][$wasteCodeId]))
						continue;
					$txt = $r['text'] ?? '';
					if (isset($r['itemDecription']) && $r['itemDecription'] !== '')
						$txt .= ' ('.$r['itemDecription'].')';

					if (!in_array($txt, $wnn[$wasteCodeId] ?? []))
					{
						$wnn[$wasteCodeId][] = $txt;
						$reportData['infoWasteCodes'][$wasteCodeId]['wasteNotes'][] = [
							'text' => $r['text'],
							'textFull' => $txt,
							'description' => $r['itemDecription'] ?? '',
						];
					}
				}
			}
		}
	}
}

