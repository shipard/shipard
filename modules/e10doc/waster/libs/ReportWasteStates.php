<?php

namespace e10doc\waster\libs;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \Shipard\Utils\World;
use \e10pro\reports\waste_cz\libs\WasteReturnEngine;
use \e10doc\core\libs\E10Utils;


/**
 * class ReportWasteStates
 */
class ReportWasteStates extends \e10doc\core\libs\reports\GlobalReport
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;
  var $calendarYear = 0;
  var $persons = [];
  var $dstUnits = 't';
  var $dstDecimals = 6;
  var $codeKindNdx = 0;

	/** @var \e10\witems\TableItems */
	var $tableItems;

  var $units = NULL;
  //var $wastes = [];

  var $stockItemsNdxs = NULL;
  var $stockItems = [];
  var $stockData = [];

  var $wasteCodesMainItems = [];

  var $wasteHandlingCodes = NULL;

	const mtIn = 0, mtOut = 1;
	const mtoInitState = 1, mtoIn = 2,
			mtoMnfOutAssembly = 3, mtoMnfInAssembly = 4, mtoMnfOutDisassembly = 5, mtoMnfInDisassembly = 6,
			mtoOut = 7;

  var $checksGroups = NULL;


	public function init ()
	{
    $this->tableItems = $this->app->table ('e10.witems.items');
    $this->wasteHandlingCodes = $this->app->cfgItem('e10doc.waster.handlingCodes', []);

    $today = Utils::today();
    $defaultYear = 'Y'.(intval($today->format('Y')));
    $this->addParam ('calendarMonth', 'calendarPeriod', ['flags' => ['quarters', 'halfs', 'years'], 'defaultValue' => $defaultYear]);

    $ckEnum = $this->codesKindEnum();
    $this->addParam('switch', 'codeKind', ['title' => 'Druh', 'switch' => $ckEnum, 'radioBtn' => 1, '__defaultValue' => 'all']);

    if ($this->subReportId === 'report')
      $this->addParam('switch', 'showUnits', ['title' => 'Jednotka', 'switch' => ['1' => 'Tuny', '0' => 'kg'], 'radioBtn' => 1, 'defaultValue' => '1']);

		parent::init();


    $this->dstUnits = 't';

    if (!$this->codeKindNdx)
      $this->codeKindNdx = intval($this->reportParams ['codeKind']['value'] ?? 1);

    if (!$this->periodBegin)
    {
      $cpBegin = $this->reportParams ['calendarPeriod']['values'][$this->reportParams ['calendarPeriod']['value']];
      if (isset($cpBegin['dateBegin']))
        $this->periodBegin = Utils::createDateTime($cpBegin['dateBegin']);
      elseif ($cpBegin['calendarYear'] !== 0)
        $this->periodBegin = Utils::createDateTime(substr($cpBegin['calendarYear'], 1).'-01-01');

      if (isset($cpBegin['dateEnd']))
        $this->periodEnd = Utils::createDateTime($cpBegin['dateEnd']);
      elseif ($cpBegin['calendarYear'] !== 0)
        $this->periodEnd = Utils::createDateTime(substr($cpBegin['calendarYear'], 1).'-12-31');

      if (is_string($cpBegin['calendarYear']) && $cpBegin['calendarYear'][0] === 'Y')
        $this->calendarYear = intval(substr($cpBegin['calendarYear'], 1));

      $this->setInfo('icon', 'reportMonthlyReport');
    }
    else
    {
      $this->periodBegin = Utils::createDateTime($this->periodBegin);
      $this->periodEnd = Utils::createDateTime($this->periodEnd);
    }

    /*
      $dday = '2025-10-15';
      $this->periodBegin = Utils::createDateTime($dday);
      $this->periodEnd = Utils::createDateTime($dday);
    */

    $this->setInfo('param', 'Období', $this->reportParams ['calendarPeriod']['activeTitle'].' ('.Utils::datef($this->periodBegin, '%d').' - '.Utils::datef($this->periodEnd, '%d').')');

    $checkWasteGroupsYear = 'Y'.$this->periodBegin->format('Y');
    $this->checksGroups = $this->app->cfgItem('e10doc.waster.checkWasteGroups.'.$checkWasteGroupsYear, NULL);
    if (!$this->checksGroups)
      $this->checksGroups = $this->app->cfgItem('e10doc.waster.checkWasteGroups.DEFAULT', NULL);

		$this->tableItems = $this->app->table ('e10.witems.items');
  }

  function createContent ()
	{
    $this->stockData = [];
    $this->loadData_Stock($this->stockData);
    $this->loadStockItems();

		switch ($this->subReportId)
		{
			case '':
			case 'waste': $this->createContent_Wastes (); break;
			case 'stock': $this->createContent_Stock (); break;
			case 'checks': $this->createContent_Checks (); break;
			case 'whc': $this->createContent_Whc (); break;
		}
	}

  public function createContent_Wastes()
  {
		$data = [];
    $this->loadData_Wastes($data);

		$h = [
      'wasteCode' => ' Kód',
      'wasteName' => 'Text',
      'quantityIS' => '+Poč. stav',
      'quantityIn' => '+Příjem',
      'quantityOut' => '+Výdej',
      'quantityState' => '+Stav',
    ];

    foreach ($data as &$wc)
    {
      $wasteCode = $wc['wasteCode'];

      if (isset($this->wasteCodesMainItems[$wasteCode]))
      {
        foreach ($this->wasteCodesMainItems[$wasteCode] as $dstStockItemNdx => $cnt)
        {
          $itemLabel = ['text' => $this->stockItems[$dstStockItemNdx]['fullName'] ?? '----', 'class' => 'break'];
          if ($cnt === 1)
            $itemLabel['class'] .= ' e10-off';
          else
            $itemLabel['class'] .= ' e10-error';
          $wc['wasteName'][] = $itemLabel;
        }
      }
    }

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE, 'params' => ['precision' => $this->dstDecimals]]);

		$this->setInfo('title', 'Celkový přehled o pohybu odpadů');
    $this->setInfo('note', '1', 'Všechna množství jsou v tunách');
  }

  public function loadData_Wastes(&$data)
  {
    $year = intval($this->periodBegin->format('Y'));
    $month = intval($this->periodBegin->format('m'));
    $day = intval($this->periodBegin->format('d'));

    if (!($day === 1 && $month === 1))
      $this->loadWasteInitStates($data);

    $q = [];

    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG, [rows].[dir], [rows].[wasteHandlingCode],');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);
		array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].[wasteHandlingCode], [rows].[dir]');
    array_push ($q, ' ORDER BY nomencItems.fullName, nomencItems.itemId');

		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
      $wcId = 'W'.$r['wasteCodeNomenc'];

      if (!isset($data[$wcId]))
      {
        $data[$wcId] = [
          'wasteCode' => $r['itemId'],
          'wasteName' => [
            ['text' => $r['fullName'], 'class' => 'e10-bold']
          ],
        ];
      }

      $data[$wcId]['quantityState'] ??= 0.0;

      $whc = $this->wasteHandlingCodes[$r['wasteHandlingCode']] ?? NULL;
      if (($whc['isEndState'] ?? 0) == 1)
        continue;

      $quantity = $this->quantity($r['quantityKG'], 'kg', $this->dstUnits);
      switch ($whc['dir'])
      {
        case WasteReturnEngine::whcDirIn:
          $data[$wcId]['quantityIn'] ??= 0.0;
          $data[$wcId]['quantityIn'] += $quantity;
          $data[$wcId]['quantityState'] += $quantity;
          break;

        case WasteReturnEngine::whcDirOut:
          $data[$wcId]['quantityOut'] ??= 0.0;
          $data[$wcId]['quantityOut'] += $quantity;
          $data[$wcId]['quantityState'] -= $quantity;
          break;

        case WasteReturnEngine::whcDirInitState:
          $data[$wcId]['quantityIS'] ??= 0.0;
          $data[$wcId]['quantityIS'] += $quantity;
          $data[$wcId]['quantityState'] += $quantity;
          break;

        case WasteReturnEngine::whcDirProduction:
        case WasteReturnEngine::whcDirMove:
          if (intval($whc['isEndState'] ?? 0) == 0)
          {
            if ($r['dir'] == WasteReturnEngine::rowDirIn)
            {
              $data[$wcId]['quantityIn'] ??= 0.0;
              $data[$wcId]['quantityIn'] += $quantity;
              $data[$wcId]['quantityState'] += $quantity;
            }
            elseif ($r['dir'] == WasteReturnEngine::rowDirOut)
            {
              $data[$wcId]['quantityOut'] ??= 0.0;
              $data[$wcId]['quantityOut'] += $quantity;
              $data[$wcId]['quantityState'] -= $quantity;
            }
          }
          break;
      }
		}

    // add init states to end states
    /*
    foreach ($data as $wcId => &$wc)
    {
      if (isset($wc['quantityIS']))
      {
        $wc['quantityState'] += $wc['quantityIS'];
      }
    }
    */
  }

  public function loadWasteInitStates(&$data)
  {
    $year = intval($this->periodBegin->format('Y'));
    $periodYearBegin = new \DateTime("{$year}-01-01");

    $q = [];

    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG, [rows].[dir], [rows].[wasteHandlingCode], [rows].[unit],');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);

    array_push ($q, ' AND [rows].[dateAccounting] >= %d', $periodYearBegin);
    array_push ($q, ' AND [rows].[dateAccounting] < %d', $this->periodBegin);

		array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].[wasteHandlingCode], [rows].[unit]');
    array_push ($q, ' ORDER BY nomencItems.fullName, nomencItems.itemId');

		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
      $wcId = 'W'.$r['wasteCodeNomenc'];

      if (!isset($data[$wcId]))
      {
        $data[$wcId] = [
          'wasteCode' => $r['itemId'],
          'wasteName' => [
            ['text' => $r['fullName'], 'class' => 'e10-bold']
          ],
        ];
      }

      $quantity = $this->quantity($r['quantityKG'], 'kg', $this->dstUnits);
      $data[$wcId]['quantityState'] ??= 0.0;
      $data[$wcId]['quantityIS'] ??= 0.0;
      $whc = $this->wasteHandlingCodes[$r['wasteHandlingCode']] ?? NULL;
      switch ($whc['dir'])
      {
        case WasteReturnEngine::whcDirIn:
        case WasteReturnEngine::whcDirInitState:
//        case WasteReturnEngine::whcDirProduction:
//          $data[$wcId]['quantityIS'] ??= 0.0;
          $data[$wcId]['quantityIS'] += $quantity;
          $data[$wcId]['quantityState'] += $quantity;
          break;
        case WasteReturnEngine::whcDirOut:
//        case WasteReturnEngine::whcDirMove:
  //        $data[$wcId]['quantityIS'] ??= 0.0;
          $data[$wcId]['quantityIS'] -= $quantity;
          $data[$wcId]['quantityState'] -= $quantity;
          break;
      }
		}
  }

	function createContent_Stock ()
	{
		$data = $this->stockData;

    foreach ($data as &$itm)
    {
      $thisItemNdx = $itm['ndx'];
      if (isset($this->stockItems[$thisItemNdx]['wasteCodeList']) && count($this->stockItems[$thisItemNdx]['wasteCodeList']))
      {
        foreach ($this->stockItems[$thisItemNdx]['wasteCodeList'] as $wc)
        {
          $itm['n'][] = ['text' => $wc, 'class' => 'e10-off'];
        }
        //$itm['n']['suffix'] = implode(', ', $this->stockItems[$thisItemNdx]['wasteCodeList']);
        $itm['n'][] = ['text' => '', 'class' => 'break'];
      }

      if (isset($this->stockItems[$thisItemNdx]) && isset($this->stockItems[$thisItemNdx]['setItems']))
      {
        foreach ($this->stockItems[$thisItemNdx]['setItems'] as $setItemNdx => $setItemRecData)
        {
          $il = ['text' => $setItemRecData['fullName'], 'class' => 'e10-off block'];
          if (isset($setItemRecData['wasteCodesListStr']))
            $il['suffix'] = $setItemRecData['wasteCodesListStr'];
          $itm['n'][] = $il;
        }
      }
    }

		//$title = NULL;
    $this->setInfo('icon', 'e10doc-inventory/inventoryStates');
    $this->setInfo('title', 'Stavy položek');

		if (count($data))
		{
			$h = [
				'#' => '#',
        'wn' => ' id',
        'n' => 'Název',
        'initState' => '+Poč. stav',
        'in' => '+Příjem',
        'out' => '+Výdej',
        'endState' => '+Stav',
        'u' => 'Jed.',
			];
			$this->addContent([
        'type' => 'table', 'header' => $h, 'table' => $data, //'title' => $title,
        'main' => TRUE, 'params' => ['precision' => $this->dstDecimals]
      ]);
		}
	}

  protected function createContent_Checks()
  {
		$wastesData = [];
    $this->loadData_Wastes($wastesData);

    $data = [];

    foreach ($this->checksGroups as $cg)
    {
      $cgData = [
        'title' => $cg['title'],
        'wastesIn'  => 0.0,
        'wastesOut' => 0.0,
        'wastesIS' => 0.0,
        'wastesEndState' => 0.0,
        'stocksIn'  => 0.0,
        'stocksOut' => 0.0,
        'stocksIS' => 0.0,
        'stocksEndState' => 0.0,
      ];

      $isUwc = [];
      foreach ($cg['wasteCodesInOut'] as $wasteCode)
      {
        foreach ($wastesData as $wd)
        {
          if ($wd['wasteCode'] == $wasteCode)
          {
            //$cgData['wastesIS'] += $wd['quantityIS'] ?? 0.0;
            $cgData['wastesIn'] += $wd['quantityIn'] ?? 0.0;
            $cgData['wastesOut'] += $wd['quantityOut'] ?? 0.0;
            $cgData['wastesEndState'] += $wd['quantityState'] ?? 0.0;
            $isUwc[$wasteCode] = TRUE;
          }
        }
      }
      foreach ($cg['wasteCodesInitState'] as $wasteCode)
      {
        foreach ($wastesData as $wd)
        {
          if ($wd['wasteCode'] == $wasteCode)
          {
            $cgData['wastesIS'] += $wd['quantityIS'] ?? 0.0;
            if (!isset($isUwc[$wasteCode]))
              $cgData['wastesEndState'] += $wd['quantityIS'] ?? 0.0;
          }
        }
      }

      foreach ($cg['items'] as $itemNdx)
      {
        if (isset($this->stockData[$itemNdx]))
        {
          $cgData['stocksIn'] += $this->stockData[$itemNdx]['in'] ?? 0.0;
          $cgData['stocksOut'] += $this->stockData[$itemNdx]['out'] ?? 0.0;
          $cgData['stocksIS'] += $this->stockData[$itemNdx]['initState'] ?? 0.0;
          $cgData['stocksEndState'] += $this->stockData[$itemNdx]['endState'] ?? 0.0;
        }
      }

      $data[] = $cgData;
    }

    foreach ($data as &$d)
    {
      if (abs($d['wastesIn'] - $d['stocksIn']) < 0.000050)
      {
        $d['_options']['cellClasses']['wastesIn'] = 'e10-row-plus';
        $d['_options']['cellClasses']['stocksIn'] = 'e10-row-plus';
      }

      if (abs($d['wastesOut'] - $d['stocksOut']) < 0.000050)
      {
        $d['_options']['cellClasses']['wastesOut'] = 'e10-row-plus';
        $d['_options']['cellClasses']['stocksOut'] = 'e10-row-plus';
      }

      if (abs($d['wastesIS'] - $d['stocksIS']) < 0.000050)
      {
        $d['_options']['cellClasses']['wastesIS'] = 'e10-row-plus';
        $d['_options']['cellClasses']['stocksIS'] = 'e10-row-plus';
      }

      if (abs($d['wastesEndState'] - $d['stocksEndState']) < 0.000050)
      {
        $d['_options']['cellClasses']['wastesEndState'] = 'e10-row-plus';
        $d['_options']['cellClasses']['stocksEndState'] = 'e10-row-plus';
      }
    }


    $h = [
      '#' => '#',
      'title' => 'Kontrolní skupiny',

      'wastesIS' => '+Poč. stav O',
      'stocksIS' => '+Poč. stav Z',

      'wastesIn' => '+Příjem O',
      'stocksIn' => '+Příjem Z',

      'wastesOut' => '+Výdej O',
      'stocksOut' => '+Výdej Z',

      'wastesEndState' => '+Kon. stav O',
      'stocksEndState' => '+Kon. stav Z',
    ];
    $this->addContent([
      'type' => 'table', 'header' => $h, 'table' => $data, //'title' => $title,
      'main' => TRUE, 'params' => ['precision' => $this->dstDecimals, 'forceTableClass' => 'fullWidth default stripped']
    ]);

    $this->setInfo('note', '1', 'Všechna množství jsou v tunách');

    $this->addContent(['type' => 'text', 'subtype' => 'code', 'text' => Json::lint($wastesData)]);

    $this->setInfo('title', 'Kontrola Odpady vs Zásoby');
  }

	function loadData_Stock (&$data)
	{
		$q = [];

    array_push($q, 'SELECT item, unit, moveTypeOrder, SUM(quantity) as quantity, SUM(inv.price) as priceAll, ');
    array_push($q, ' items.fullName as fullName, items.id as itemId, items.docState as itemDocState, items.docStateMain as itemDocStateMain ');
    array_push($q, ' FROM [e10doc_inventory_journal] AS [inv]');
    array_push($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [inv].[item] = [items].[ndx]');
    array_push($q, " WHERE 1");
		array_push($q, " AND [date] >= %d", $this->periodBegin);
    array_push($q, ' AND [date] <= %d', $this->periodEnd);
		//if ($this->warehouse)
		//	array_push($q, " AND [warehouse] = %i", $this->warehouse);
		array_push($q, " GROUP BY item, unit, moveTypeOrder");
    //array_push($q, " HAVING [quantity] != 0 OR priceAll != 0");
		$rows = $this->app->db()->query($q);

		$data = [];
    $this->stockItemsNdxs = [];
		forEach ($rows as $r)
		{
      if ($r['unit'] !== 'kg' && $r['unit'] !== 'g' && $r['unit'] !== 't')
        continue;

			$itemNdx = $r['item'];
      if (!in_array($itemNdx, $this->stockItemsNdxs))
        $this->stockItemsNdxs[] = $itemNdx;

			$itemRecData = ['ndx' => $r['itemId'], 'docState' => $r['itemDocState'], 'docStateMain' => $r['itemDocStateMain']];
			$docStates = $this->tableItems->documentStates($itemRecData);
			$docStateClass = $this->tableItems->getDocumentStateInfo($docStates, $itemRecData, 'styleClass');

      if (!isset($data[$itemNdx]))
      {
        $itm = [
          'ndx' => $itemNdx,
          'wn' => ['text' => $r['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $itemNdx],
          'n' => [
            ['text' => $r['fullName'], 'class' => 'e10-bold']
          ],
          'initState' => 0.0,//$this->quantity($r['quantity'], $r['unit'], $this->dstUnits),
          'in' => 0.0,
          'out' => 0.0,
          'endState' => 0.0,
          'u' => $this->dstUnits,
          '_options' => array('cellClasses' => array('wn' => $docStateClass))
        ];
			  $data[$itemNdx] = $itm;
      }

      switch ($r['moveTypeOrder'])
      {
        case self::mtoInitState:
          $data[$itemNdx]['initState'] += $this->quantity($r['quantity'], $r['unit'], $this->dstUnits);
          break;
        case self::mtoIn:
        case self::mtoMnfInAssembly:
          $data[$itemNdx]['in'] += $this->quantity($r['quantity'], $r['unit'], $this->dstUnits);
          break;
        case self::mtoOut:
        case self::mtoMnfOutAssembly:
          $data[$itemNdx]['out'] += $this->quantity(- $r['quantity'], $r['unit'], $this->dstUnits);
          break;
      }
      $data[$itemNdx]['endState'] += $this->quantity($r['quantity'], $r['unit'], $this->dstUnits);
		}
	}

  protected function loadStockItems()
  {
    if (!count($this->stockItemsNdxs))
      return;
    $q = [];
    array_push($q, 'SELECT * FROM [e10_witems_items] WHERE ndx IN %in', $this->stockItemsNdxs);
    $rows = $this->app->db()->query($q);
    foreach ($rows as $r)
    {
      $dstStockItemNdx = $r['ndx'];
      $this->stockItems[$dstStockItemNdx] = $r->toArray();
      $this->stockItems[$dstStockItemNdx]['setItems'] = [];
      $this->stockItems[$dstStockItemNdx]['wasteCodeList'] = [];

      $qs = [];
      array_push($qs, 'SELECT [setRows].* ');
      array_push($qs, ' FROM [e10_witems_itemsets] AS [setRows]');
      array_push($qs, ' LEFT JOIN [e10_witems_items] AS [ownerItems] ON [setRows].[itemOwner] = [ownerItems].[ndx]');
      array_push($qs, ' WHERE [setRows].[item] = %i', $dstStockItemNdx);
      array_push($qs, ' AND [ownerItems].docState IN %in', [4000, 8000]);
      array_push($qs, ' AND [ownerItems].isSet = %i', 1);
      array_push($qs, ' AND (setRows.validFrom IS NULL OR setRows.validFrom <= %d', $this->periodBegin, ')');
      array_push($qs, ' AND (setRows.validTo IS NULL OR setRows.validTo >= %d', $this->periodBegin, ')');
      array_push($qs, ' AND (ownerItems.validFrom IS NULL OR ownerItems.validFrom <= %d', $this->periodBegin, ')');
      array_push($qs, ' AND (ownerItems.validTo IS NULL OR ownerItems.validTo >= %d', $this->periodBegin, ')');

      $setItems = $this->app->db()->query($qs);
      foreach ($setItems as $si)
      {
        $itemNdx = $si['itemOwner'];
        $itemRecData = $this->tableItems->loadItem($itemNdx);

        $wasteCodes = $this->loadItemCodes($itemNdx);
        $itemRecData['wasteCodes'] = $wasteCodes;
        if (isset($itemRecData['wasteCodes']) && count($itemRecData['wasteCodes']))
        {
          $wcs = [];
          foreach ($itemRecData['wasteCodes'] as $wc)
          {
            $wcs[] = $wc['code'];
            if (!in_array($wc['code'], $this->stockItems[$dstStockItemNdx]['wasteCodeList']))
              $this->stockItems[$dstStockItemNdx]['wasteCodeList'][] = $wc['code'];

            $this->wasteCodesMainItems[$wc['code']][$dstStockItemNdx] ??= 0;
            $this->wasteCodesMainItems[$wc['code']][$dstStockItemNdx]++;
          }
          $itemRecData['wasteCodesList'] = $wcs;
          $itemRecData['wasteCodesListStr'] = implode(', ', $wcs);
        }

        $this->stockItems[$dstStockItemNdx]['setItems'][$itemNdx] = $itemRecData;
      }
    }
  }

  protected function loadItemCodes($itemNdx, $stage = 0)
  {
		$q = [];
		array_push ($q, 'SELECT [codes].*, [nomencItems].fullName AS nomencName');
		array_push ($q, ' FROM [e10_witems_itemCodes] AS [codes]');
		array_push ($q, ' LEFT JOIN  [e10_base_nomencItems] AS [nomencItems] ON [codes].[itemCodeNomenc] = [nomencItems].[ndx]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [codes].[item] = %i', $itemNdx);
		array_push ($q, ' AND ([codes].[validFrom] IS NULL', ' OR [codes].[validFrom] <= %d)', $this->periodBegin);
		array_push ($q, ' AND ([codes].[validTo] IS NULL', ' OR [codes].[validTo] >= %d)', $this->periodBegin);
    array_push ($q, ' AND [codes].[codeKind] = %i', $this->codeKindNdx);
    if ($stage === 0)
      array_push ($q, ' AND [codes].[wasteOrigin] = %i', 3);
		array_push ($q, ' ORDER BY [codes].systemOrder');
    $rows = $this->app->db()->query ($q);
    $codes = [];
    foreach ($rows as $r)
    {
      $codeId = strval($r['itemCodeText']);
      $codes[$codeId] = [
        'code' => $r['itemCodeText'],
        'name' => $r['nomencName'],
      ];
    }

    if ($stage === 0 && count($codes) === 0)
      $codes = $this->loadItemCodes($itemNdx, 1);

    return $codes;
  }

  protected function createContent_Whc()
  {
    $data = [];
    $sum = ['quantityIn' => 0.0,'quantityOut' => 0.0,];
    $lastWasteCode = '';

    $q = [];
    array_push ($q, 'SELECT [rows].wasteCodeNomenc, [rows].[dir], [rows].wasteHandlingCode, ');
    array_push ($q, ' SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
    array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);
    array_push ($q, ' AND [rows].[quantityKG] != 0');
    array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].[dir], [rows].wasteHandlingCode');
    array_push ($q, ' ORDER BY [rows].wasteCodeNomenc, [rows].[dir], [rows].wasteHandlingCode');

    $rows = $this->app->db()->query ($q);
    foreach ($rows as $r)
    {
      if ($lastWasteCode !== $r['itemId'])
      {
        if ($lastWasteCode !== '')
        {
          $sumRow = [
            'title' => 'CELKEM:',
            'quantityIn' => $sum['quantityIn'],
            'quantityOut' => $sum['quantityOut'],
            '_options' => ['class' => 'subtotal',]
          ];
          $data[] = $sumRow;
        }

        $header = [
          'title' => ['text' => $r['itemId'], 'suffix' => $r['fullName']],
          '_options' => [
            'colSpan' => ['title' => 3],
            'class' => 'subheader',
          ]
        ];
        $header['_options']['beforeSeparator'] = 'separator';
        $data[] = $header;
        $sum = ['quantityIn' => 0.0,'quantityOut' => 0.0,];
      }

      $whc = $this->wasteHandlingCodes[$r['wasteHandlingCode']] ?? NULL;
      $item = [
        'code' => $r['wasteHandlingCode'],
        'title' => $whc['sn'] ?? '!!!',
        'dir' => $r['dir'],
        'whc' => $r['wasteHandlingCode'],
      ];

      if ($r['dir'] === WasteReturnEngine::whcDirIn)
      {
        $item['quantityIn'] = $this->quantity($r['quantityKG'], 'kg', $this->dstUnits);
        $sum['quantityIn'] += $item['quantityIn'];
      }
      elseif ($r['dir'] === WasteReturnEngine::whcDirOut)
      {
        $item['quantityOut'] = $this->quantity($r['quantityKG'], 'kg', $this->dstUnits);
        $sum['quantityOut'] += $item['quantityOut'];
      }

      $data[] = $item;
      $lastWasteCode = $r['itemId'];
    }

    $sumRow = [
      'title' => 'CELKEM:',
      'quantityIn' => $sum['quantityIn'],
      'quantityOut' => $sum['quantityOut'],
      '_options' => ['class' => 'subtotal',]
    ];
    $data[] = $sumRow;


    $h = [
      '#' => '#',
      'title' => 'Název',
      'quantityIn' => '+Příjem',
      'quantityOut' => '+Výdej',
    ];
    $this->addContent([
      'type' => 'table', 'header' => $h, 'table' => $data, //'title' => $title,
      'main' => TRUE, 'params' => ['precision' => $this->dstDecimals]
    ]);

    $this->setInfo('title', 'Kódy nakládání');

  }

  protected function codesKindEnum()
  {
    $enum = [];
    $ack = $this->app()->cfgItem('e10.witems.codesKinds');
    foreach ($ack as $ackNdx => $ackDef)
    {
      if ($ackDef['codeType'] !== 31)
        continue;

      $enum[$ackNdx]  = $ackDef['reportSwitchTitle'];
    }
    return $enum;
  }

	protected function quantity ($quantity, $srcUnit, $dstUnit)
	{
    $ucc = E10Utils::unitsConversionCoefficient($this->app(), $srcUnit, $dstUnit);
		return round($quantity * $ucc, $this->dstDecimals);
	}

  public function subReportsList ()
	{
		$d[] = ['id' => 'waste', 'icon' => 'system/actionRecycle', 'title' => 'Odpady'];
		$d[] = ['id' => 'stock', 'icon' => 'tables/e10.witems.items', 'title' => 'Zásoby'];
		$d[] = ['id' => 'whc', 'icon' => 'tables/e10.witems.items', 'title' => 'Kódy nakládání'];
    if ($this->checksGroups)
		  $d[] = ['id' => 'checks', 'icon' => 'system/iconWarning', 'title' => 'Kontrola'];

		return $d;
	}
}
