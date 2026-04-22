<?php
namespace e10doc\gen\libs;
require_once __SHPD_MODULES_DIR__ . 'e10doc/inventory/inventory.php';
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \Shipard\Utils\World;
use \e10pro\reports\waste_cz\libs\WasteReturnEngine;
use \e10doc\core\libs\E10Utils;
use e10doc\inventory\Inventory;

/**
 * class ReportStockInvoices
 */
class ReportStockInvoices extends \e10doc\core\libs\reports\GlobalReport
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

  var $data = [];

	public function init ()
	{
    $this->tableItems = $this->app->table ('e10.witems.items');

    $today = Utils::today();
    $defaultYear = 'Y'.(intval($today->format('Y')));
    $this->addParam ('calendarMonth', 'calendarPeriod', ['flags' => ['quarters', 'halfs', 'years'], 'defaultValue' => $defaultYear]);

		parent::init();

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


		$this->tableItems = $this->app->table ('e10.witems.items');
  }

  function createContent ()
	{
    //$this->loadStockItems();

		switch ($this->subReportId)
		{
			case '':
			case 'all': $this->createContent_All (); break;
		}
	}

  public function createContent_All()
  {

    $this->loadData_Stock ($this->data);
    $this->loadData_Invoices ($this->data);

    //$this->loadData_Wastes($data);


    $t = [];
		$h = [
      'itemId' => ' ID',
      'itemName' => 'Název',
      'invno' => 'Faktury',
      'stock' => 'Výdejky',
    ];

    foreach ($this->data as $personNdx => $personData)
    {
      $headItm = [
        'itemId' => '',
        'itemName' => $personData['personFullName'],
        'quantityIS' => $personData['initState'],
        'invno' => $personData['invno'],
        'stock' => $personData['stock'],
        'quantityState' => $personData['endState'],

        '_options' => ['class' => 'subheader'],
      ];
      $headItm['_options']['beforeSeparator'] = 'separator';
      $t[] = $headItm;

      foreach ($personData['rowsItems'] as $itemNdx => $itemData)
      {
        $itm = [
          'itemId' => $itemData['itemId'],
          'itemName' => $itemData['itemName'],
          'quantityIS' => $itemData['initState'],
          'invno' => $itemData['invno'],
          'stock' => $itemData['stock'],
          'quantityState' => $itemData['endState'],
        ];

        if (abs(round($itm['stock'] - $itm['invno'], 6)) < 0.000001)
        {
          $itm['_options']['class'] = 'e10-row-plus';
        }

        $t[] = $itm;
      }
    }

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE, 'params' => ['precision' => $this->dstDecimals]]);

		$this->setInfo('title', 'Kontrola Výdejky vs Faktury');
    $this->setInfo('note', '1', 'Všechna množství jsou v tunách');
  }

	function loadData_Stock (&$data)
	{
		$q = [];

    array_push($q, 'SELECT [docHeads].[person] AS docPersonNdx, [docHeads].[docNumber] AS [docNumber],');
    array_push($q, ' [persons].[fullName] AS [personFullName],');
    array_push($q, ' item, unit, moveTypeOrder, SUM([inv].quantity) as quantity, ');
    array_push($q, ' items.fullName as itemFullName, items.id as itemId, items.docState as itemDocState, items.docStateMain as itemDocStateMain ');
    array_push($q, ' FROM [e10doc_inventory_journal] AS [inv]');
    array_push($q, ' LEFT JOIN [e10doc_core_heads] AS [docHeads] ON [inv].docHead = [docHeads].[ndx]');
    array_push($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [inv].[item] = [items].[ndx]');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [docHeads].[person] = [persons].[ndx]');
    array_push($q, ' WHERE 1');
		array_push($q, ' AND [date] >= %d', $this->periodBegin);
    array_push($q, ' AND [date] <= %d', $this->periodEnd);
    array_push($q, ' AND [docHeads].[docType] = %s', 'stockout');
		//if ($this->warehouse)
		//	array_push($q, " AND [warehouse] = %i", $this->warehouse);
		array_push($q, ' GROUP BY [docHeads].[person], item, unit, moveTypeOrder');
    //array_push($q, " HAVING [quantity] != 0 OR priceAll != 0");
		$rows = $this->app->db()->query($q);

		forEach ($rows as $r)
		{
      if ($r['unit'] !== 'kg' && $r['unit'] !== 'g' && $r['unit'] !== 't')
        continue;

      $personNdx = $r['docPersonNdx'];
			$itemNdx = $r['item'];

      if (!isset($data[$personNdx]))
      {
        $itm = [
          'ndx' => $itemNdx,
          'personFullName' => $r['personFullName'],
          'invno' => 0.0,
          'stock' => 0.0,

          'rowsItems' => [],

          //'_options' => array('cellClasses' => array('wn' => $docStateClass))
        ];
			  $data[$personNdx] = $itm;
      }

      if (!isset($data[$personNdx]['rowsItems'][$itemNdx]))
      {
        $data[$personNdx]['rowsItems'][$itemNdx] = [
          'ndx' => $itemNdx,
          'itemId' => ['text' => $r['itemId']],
          'itemName' => $r['itemFullName'],
          'invno' => 0.0,
          'stock' => 0.0,
        ];
      }

      switch ($r['moveTypeOrder'])
      {
        case Inventory::mtoOut:
          $data[$personNdx]['stock'] += $this->quantity(- $r['quantity'], $r['unit'], $this->dstUnits);
          $data[$personNdx]['rowsItems'][$itemNdx]['stock'] += $this->quantity(- $r['quantity'], $r['unit'], $this->dstUnits);
          break;
      }
		}
	}

	function loadData_Invoices (&$data)
	{
		$q = [];

		array_push($q, 'SELECT [rows].*,');
    array_push($q, ' [docHeads].[person] AS docPersonNdx, [persons].[fullName] AS [personFullName],');
		array_push($q, ' [witems].fullName AS itemFullName, [witems].[id] AS witemId');
		array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
		array_push($q, ' LEFT JOIN [e10_witems_items] AS [witems] ON [rows].item = [witems].ndx');
    array_push($q, ' LEFT JOIN [e10doc_core_heads] AS [docHeads] ON [rows].document = [docHeads].ndx');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [docHeads].[person] = [persons].[ndx]');
    array_push($q, ' WHERE 1');
		array_push($q, ' AND [docHeads].[dateAccounting] >= %d', $this->periodBegin);
    array_push($q, ' AND [docHeads].[dateAccounting] <= %d', $this->periodEnd);
    array_push($q, ' AND [docHeads].[docType] = %s', 'invno');
		array_push($q, ' AND [rows].[rowType] = %i', 1);
		//array_push($q, ' GROUP BY [docHeads].[person], [rows].[item], [rows].[unit]');
		$rows = $this->app->db()->query($q);

		forEach ($rows as $r)
		{
      if ($r['unit'] !== 'kg' && $r['unit'] !== 'g' && $r['unit'] !== 't')
        continue;

      $personNdx = $r['docPersonNdx'];
			$itemNdx = $r['item'];

      if (!isset($data[$personNdx]))
      {
        $itm = [
          'ndx' => $itemNdx,
          'personFullName' => $r['personFullName'],
          'initState' => 0.0,
          'invno' => 0.0,
          'out' => 0.0,
          'endState' => 0.0,
          'u' => $this->dstUnits,

          'rowsItems' => [],

          //'_options' => array('cellClasses' => array('wn' => $docStateClass))
        ];
			  $data[$personNdx] = $itm;
      }

      if (!isset($data[$personNdx]['rowsItems'][$itemNdx]))
      {
        $data[$personNdx]['rowsItems'][$itemNdx] = [
          'ndx' => $itemNdx,
          'itemId' => ['text' => $r['itemId']],
          'itemName' => $r['itemFullName'],
          'initState' => 0.0,
          'invno' => 0.0,
          'out' => 0.0,
          'endState' => 0.0,
          'u' => $this->dstUnits,
        ];
      }

      $data[$personNdx]['invno'] += $this->quantity($r['quantity'], $r['unit'], $this->dstUnits);
      $data[$personNdx]['rowsItems'][$itemNdx]['invno'] += $this->quantity($r['quantity'], $r['unit'], $this->dstUnits);
		}
	}

	protected function quantity ($quantity, $srcUnit, $dstUnit)
	{
    $ucc = E10Utils::unitsConversionCoefficient($this->app(), $srcUnit, $dstUnit);
		return round($quantity * $ucc, $this->dstDecimals);
	}

  public function subReportsList ()
	{
		$d[] = ['id' => 'all', 'icon' => 'system/actionRecycle', 'title' => 'ALL'];

		return $d;
	}
}
