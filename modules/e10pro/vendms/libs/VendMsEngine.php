<?php

namespace e10pro\vendms\libs;
use \Shipard\Base\Utility;
use Shipard\Utils\Utils;


/**
 * class VendMsEngine
 */
class VendMsEngine extends Utility
{
  var $vendmsNdx = 0;
  var $vendmsCfg = NULL;
  var $vendmsRecData = NULL;

  var $boxStates = [];

  var $widgetId = '';

  var $code = '';

  CONST tctApp = 0, tctMachine = 1, tctSetup = 2;

  public function setVendMs($vendmsNdx)
  {
    $this->vendmsNdx = $vendmsNdx;
    $this->vendmsCfg = $this->app()->cfgItem('e10pro.vendms.vendms.'.$vendmsNdx, NULL);


    $this->vendmsCfg['vm'] = [
      'cntCols' => 8,
      'cntRows' => 5,

      'boxes' => [
        '0-0' => ['label' => '11', 'mm' => '00'],
        '0-1' => ['label' => '12', 'mm' => '10'],
        '0-2' => ['label' => '13', 'mm' => '20'],
        '0-3' => ['label' => '14', 'mm' => '30'],
        '0-4' => ['label' => '15', 'mm' => '40'],
        '0-5' => ['label' => '16', 'mm' => '50'],
        '0-6' => ['label' => '17', 'mm' => '60'],
        '0-7' => ['label' => '18', 'mm' => '70'],

        '1-0' => ['label' => '21', 'cols' => 2, 'mm' => '01'],
        '1-2' => ['label' => '23', 'cols' => 2, 'mm' => '21'],
        '1-4' => ['label' => '25', 'cols' => 2, 'mm' => '41'],
        '1-6' => ['label' => '27', 'cols' => 2, 'mm' => '61'],

        '2-0' => ['label' => '31', 'cols' => 2, 'mm' => '02'],
        '2-2' => ['label' => '33', 'cols' => 2, 'mm' => '22'],
        '2-4' => ['label' => '35', 'cols' => 2, 'mm' => '42'],
        '2-6' => ['label' => '37', 'cols' => 2, 'mm' => '62'],

        '3-0' => ['label' => '41', 'mm' => '03'],
        '3-1' => ['label' => '42', 'mm' => '13'],
        '3-2' => ['label' => '43', 'mm' => '23'],
        '3-3' => ['label' => '44', 'mm' => '33'],
        '3-4' => ['label' => '45', 'mm' => '43'],
        '3-5' => ['label' => '46', 'mm' => '53'],
        '3-6' => ['label' => '47', 'mm' => '63'],
        '3-7' => ['label' => '48', 'mm' => '73'],

        '4-0' => ['label' => '51', 'mm' => '04'],
        '4-1' => ['label' => '52', 'mm' => '14'],
        '4-2' => ['label' => '53', 'mm' => '24'],
        '4-3' => ['label' => '54', 'mm' => '34'],
        '4-4' => ['label' => '55', 'mm' => '44'],
        '4-5' => ['label' => '56', 'mm' => '54'],
        '4-6' => ['label' => '57', 'mm' => '64'],
        '4-7' => ['label' => '58', 'mm' => '74'],
      ],
    ];
  }

  protected function createVMTable(&$vmHeader, &$vmTable, $tableType)
  {
    $this->loadBoxStates();

    for ($x = 0; $x < $this->vendmsCfg['vm']['cntCols']; $x++)
    {
      $colId = 'C'.$x;
      $vmHeader[$colId] = '|C'.$x;
    }

    $cellCss = 'width: '.round(100 / $this->vendmsCfg['vm']['cntCols'], 3).'%;';

    for ($y = 0; $y < $this->vendmsCfg['vm']['cntRows']; $y++)
    {
      $row = [];

      for ($x = 0; $x < $this->vendmsCfg['vm']['cntCols']; $x++)
      {
        $colId = 'C'.$x;
        $cellId = $y.'-'.$x;

        $box = $this->vendmsCfg['vm']['boxes'][$cellId] ?? NULL;
        if (!$box)
          continue;

        $cellBoxRecData = $this->cellBox($cellId);

        $col = [];
        if ($cellBoxRecData)
        {
          $boxNdx = $cellBoxRecData['ndx'];
          $quantity = 0;
          if (isset($this->boxStates[$boxNdx]))
           $quantity = $this->boxStates[$boxNdx];

          if ($tableType === self::tctApp)
          {
            $cellLabel = [
              'text' => $box['label'], 'docAction' => 'edit', 'table' => 'e10pro.vendms.vendmsBoxes', 'pk' => $cellBoxRecData['ndx'],
              'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId, 'actionClass' => 'h1',
            ];
            $col[] = $cellLabel;
            if (isset($cellBoxRecData['witem']))
              $col[] = ['text' => $cellBoxRecData['witem']['shortName'], 'class' => 'break'];

            $col[] = [
              'text' => $quantity.' ks' , 'class' => 'break',
              'type' => 'action', 'action' => 'addwizard',
              'data-addparams' => 'boxNdx='.$boxNdx.'&'.'itemNdx='.$cellBoxRecData['witem']['ndx'],
              'data-class' => 'e10pro.vendms.libs.WizardBoxQuantity',
              'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId,
            ];
          }
          elseif ($tableType === self::tctMachine)
          {
            $cellLabel = ['text' => $box['label']];
            $col[] = $cellLabel;
            $row['_options']['cellData'][$colId]['item-ndx'] = $cellBoxRecData['witem']['ndx'];
            $row['_options']['cellData'][$colId]['item-name'] = $cellBoxRecData['witem']['shortName'];
            $row['_options']['cellData'][$colId]['item-price'] = $cellBoxRecData['witem']['priceSellTotal'];
            $row['_options']['cellData'][$colId]['box-id'] = $cellId;
            $row['_options']['cellData'][$colId]['box-ndx'] = $boxNdx;
            $row['_options']['cellData'][$colId]['box-mm'] = $box['mm'];

            if ($quantity > 0)
            {
              $row['_options']['cellData'][$colId]['action'] = 'vmBuyGetCard';
              $row['_options']['cellClasses'][$colId] = 'shp-widget-action';
            }
            else
              $row['_options']['cellClasses'][$colId] = 'boxIsEmpty';
          }
          elseif ($tableType === self::tctSetup)
          {
            $cellLabel = ['text' => $box['label'], 'class' => 'vm-box-label'];
            $col[] = $cellLabel;
            $col[] = [
              'text' => $quantity.' ks' , 'class' => 'vm-box-quantity',
            ];

            $row['_options']['cellData'][$colId]['item-ndx'] = $cellBoxRecData['witem']['ndx'];
            $row['_options']['cellData'][$colId]['item-name'] = $cellBoxRecData['witem']['shortName'];
            $row['_options']['cellData'][$colId]['item-price'] = $cellBoxRecData['witem']['priceSellTotal'];
            $row['_options']['cellData'][$colId]['box-id'] = $cellId;
            $row['_options']['cellData'][$colId]['box-ndx'] = $boxNdx;
            $row['_options']['cellData'][$colId]['box-label'] = $box['label'];
            $row['_options']['cellData'][$colId]['action'] = 'vmBoxSetQuantity';
            $row['_options']['cellClasses'][$colId] = 'shp-widget-action';
          }
        }
        else
        {
          if ($tableType === self::tctApp)
          {
            $cellLabel = [
              'text' => $box['label'], 'docAction' => 'new', 'table' => 'e10pro.vendms.vendmsBoxes',
              'addParams' => '__vm='.$this->vendmsNdx.'&__cellId='.$cellId,
              'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId, 'actionClass' => 'h1',
            ];
            $col[] = $cellLabel;
          }
        }

        if (count($col))
          $row[$colId] = $col;

        if (isset($box['cols']))
        {
          $row['_options']['colSpan'][$colId] = $box['cols'];
        }

        $row['_options']['cellCss'][$colId] = $cellCss;
      }

      $vmTable[] = $row;
    }
  }

  protected function cellBox($cellId)
  {
    $q = [];
    array_push($q, 'SELECT * FROM [e10pro_vendms_vendmsBoxes]');
    array_push($q, ' WHERE [vm] = %i', $this->vendmsNdx);
    array_push($q, ' AND [cellId] = %s', $cellId);

    $cell = $this->db()->query($q)->fetch();
    if ($cell)
    {
      $witem = $this->app()->loadItem($cell['item'], 'e10.witems.items');
      $cellInfo = $cell->toArray();
      if ($witem)
        $cellInfo['witem'] = $witem;

      return $cellInfo;
    }

    return NULL;
  }

  public function createCodeOverview()
  {
    $this->code = '';

    $vmHeader = [];
    $vmTable = [];
    $this->createVMTable($vmHeader, $vmTable, self::tctApp);

    $tableRendeder = new \Shipard\Utils\TableRenderer($vmTable, $vmHeader, ['tableClass' => 'default fullWidth', 'hideHeader' => 1], $this->app());
    $this->code .= $tableRendeder->render();
  }

  public function createCodeMachine()
  {
    $this->code = '';

    $vmHeader = [];
    $vmTable = [];
    $this->createVMTable($vmHeader, $vmTable, self::tctMachine);

    $tableRendeder = new \Shipard\Utils\TableRenderer($vmTable, $vmHeader, ['tableClass' => 'fullWidth vmSelectBox', 'hideHeader' => 1], $this->app());
    $this->code .= $tableRendeder->render();
  }

  public function createCodeSetup()
  {
    $this->code = '';

    $vmHeader = [];
    $vmTable = [];
    $this->createVMTable($vmHeader, $vmTable, self::tctSetup);

    $tableRendeder = new \Shipard\Utils\TableRenderer($vmTable, $vmHeader, ['tableClass' => 'fullWidth vmSelectBox', 'hideHeader' => 1], $this->app());
    $this->code .= $tableRendeder->render();
  }


  public function loadBoxStates()
  {
    $q = [];
    array_push($q, 'SELECT [box], SUM(quantity) AS q');
    array_push($q, ' FROM [e10pro_vendms_vendmsJournal]');
    array_push($q, ' GROUP BY 1');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->boxStates[$r['box']] = $r['q'];
    }
  }
}
