<?php

namespace e10pro\condo\libs;
use \Shipard\Utils\Json;
use \Shipard\Utils\Utils;


/**
 * class FlatsSettlementEngine
 */
class FlatsSettlementEngine extends \e10doc\reporting\libs\CalcReportEngine
{
  var $srcRowsData = [];
  var $results = [];

  var $unitM3 = '㎥';
  var $unitMoney = 'Kč';

  var $rowContents = [];

  var $flatWorkOrderRecData;
  var $flatWorkOrderRecDataCfg;
  var $flatAreaRatio = '';
  var $flatAreaRatioFlat = 0;
  var $flatAreaRatioBuilding = 0;

  var $partMarks = [
    'flat_info' => 'A',
    'flat_persons' => 'B',
    'flat_recap' => 'C',
    'recap_meters' => 'D',
    'cold_water' => 'E',
    'warm_water' => 'F',
    'electricity_common' => 'G',
    'insurance' => 'H',
    'administration' => 'I',

    'recap_advances' => 'J',
  ];

  var $advancesList = [
    'flat_water_cold_advance' => ['title' => 'Záloha na studenou vodu', 'account' => '324210'],
    'flat_water_cold_warm_advance' => ['title' => 'Záloha na vodu v TUV', 'account' => '324220'],
    'flat_water_heating_advance' => ['title' => 'Záloha na ohřev vody (plyn)', 'account' => '324230'],
    'flat_electricity_common_advance' => ['title' => 'Záloha na společnou elektřinu', 'account' => '324240'],
    'flat_insurance_advance' => ['title' => 'Záloha na pojištění domu', 'account' => '324310'],
    'flat_administration_advance' => ['title' => 'Záloha na správu domu', 'account' => '324320'],

    'flat_repair_fund_advance' => ['title' => 'Zálohy na opravy a údržbu domu', 'account' => '324610'],
    'flat_renovation_fund_advance' => ['title' => 'Zálohy na fond obnovy investic', 'account' => '324620'],
  ];

  protected function prepareSrcHeadData()
  {
  }

  protected function prepareFlat($workOrderNdx)
  {
    $rowData = [];

    // -- meters
    $mve = new \e10pro\meters\libs\MetersValues ($this->app());
    $mve->setQueryParam('workOrder', $workOrderNdx);
    $mve->setQueryParam('dateBegin', $this->calcReportRecData['dateBegin']);
    $mve->setQueryParam('dateEnd', $this->calcReportRecData['dateEnd']);
    $mve->load();

    $rowData['flat_water_cold_quantity'] = $mve->data['kindMetersValues'][3]['totalValue'] ?? 9999999;
    $rowData['flat_water_warm_quantity'] = $mve->data['kindMetersValues'][4]['totalValue'] ?? 9999999;

    // acc values
    $av = new \e10doc\reporting\libs\AccValues($this->app());
    $av->setQueryParam('workOrder', $workOrderNdx);
    $av->setQueryParam('dateBegin', $this->calcReportRecData['dateBegin']);
    $av->setQueryParam('dateEnd', $this->calcReportRecData['dateEnd']);

    $sum = $av->loadAccountSum('324210');
    $rowData['flat_water_cold_advance'] = $sum['sumAmount'];

    $sum = $av->loadAccountSum('324220');
    $rowData['flat_water_cold_warm_advance'] = $sum['sumAmount'];

    $sum = $av->loadAccountSum('324230');
    $rowData['flat_water_heating_advance'] = $sum['sumAmount'];

    $sum = $av->loadAccountSum('324240');
    $rowData['flat_electricity_common_advance'] = $sum['sumAmount'];

    $sum = $av->loadAccountSum('324310');
    $rowData['flat_insurance_advance'] = $sum['sumAmount'];

    $sum = $av->loadAccountSum('324320');
    $rowData['flat_administration_advance'] = $sum['sumAmount'];


    $rowData['flat_consumption_total_advance'] = round(
      $rowData['flat_water_cold_advance'] + $rowData['flat_water_cold_warm_advance'] + $rowData['flat_water_heating_advance']
    , 3);

    $rowData['flat_common_services_total_advance'] = round(
      $rowData['flat_electricity_common_advance'] + $rowData['flat_insurance_advance'] + $rowData['flat_administration_advance']
    , 3);

    $this->srcRowsData['prepared'][$workOrderNdx]['srcRowData'] = $rowData;
  }

  protected function prepareSrcRowsData()
  {
    $srcWorkOrerKind = 1;

    $q = [];
    array_push ($q, 'SELECT [wo].*');
    array_push ($q, ' FROM [e10mnf_core_workOrders] AS [wo]');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [wo].[docKind] = %i', $srcWorkOrerKind);
    array_push ($q, ' ORDER BY [wo].[docNumber]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->srcRowsData['prepared'][$r['ndx']] = ['workOrder' => $r['ndx'], 'woTitle' => $r['title']];
      $this->prepareFlat($r['ndx']);
    }
  }

  protected function savePreparedSrcData()
  {
    $all_water_cold_quantity_meters = 0.000;
    $all_water_warm_quantity = 0.000;

    // -- rows
    $usedPks = [];
    $idx = 1;
    foreach ($this->srcRowsData['prepared'] as $woRowNdx => $woRow)
    {
      $exist = $this->db()->query('SELECT * FROM [e10doc_reporting_calcReportsRowsSD] WHERE [report] = %i', $this->calcReportNdx,
                ' AND [workOrder] = %i', $woRowNdx)->fetch();

      if ($exist)
      {
        $update = [
          'title' => $woRow['woTitle'],
          'rowOrder' => $idx,
          'srcRowData' => Json::lint($this->srcRowsData['prepared'][$woRowNdx]['srcRowData']),
        ];
        $this->db()->query('UPDATE [e10doc_reporting_calcReportsRowsSD] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);

        $usedPks[] = $exist['ndx'];
      }
      else
      {
        $insert = [
          'report' => $this->calcReportNdx,
          'title' => $woRow['woTitle'],
          'rowOrder' => $idx,
          'workOrder' => $woRowNdx,
          'srcRowData' => Json::lint($this->srcRowsData['prepared'][$woRowNdx]['srcRowData']),
        ];
        $this->db()->query('INSERT INTO [e10doc_reporting_calcReportsRowsSD] ', $insert);
        $newRowNdx = intval($this->db()->getInsertId());
        $usedPks[] = $newRowNdx;
      }

      $all_water_warm_quantity = round($all_water_warm_quantity + $this->srcRowsData['prepared'][$woRowNdx]['srcRowData']['flat_water_warm_quantity'] ?? 99999999, 3);

      $flatWaterQuantity = round(($this->srcRowsData['prepared'][$woRowNdx]['srcRowData']['flat_water_warm_quantity'] ?? 9999) +
                                ($this->srcRowsData['prepared'][$woRowNdx]['srcRowData']['flat_water_cold_quantity'] ?? 9999), 3);
      $all_water_cold_quantity_meters = round ($all_water_cold_quantity_meters + $flatWaterQuantity, 3);

      $idx++;
    }

    // -- head
    $this->srcHeaderData['all_water_warm_quantity'] = $all_water_warm_quantity;
    $this->srcHeaderData['all_water_cold_quantity_meters'] = $all_water_cold_quantity_meters;

    $headUpdate = [
      'srcHeaderData' => Json::lint($this->srcHeaderData),
    ];
    $this->db()->query('UPDATE [e10doc_reporting_calcReports] SET ', $headUpdate, ' WHERE [ndx] = %i', $this->calcReportNdx);
  }

  protected function makeResults()
  {
    $this->numbers->addSection('src-all', 'Zdrojové údaje k domu');

    //$scInfoSrcHead = $this->tableCalcReports->subColumnsInfo ($this->calcReportRecData, 'srcHeaderData');
    //$this->numbers->addSubColumns ('src', 'all', $scInfoSrcHead, $this->srcHeaderData);

    $this->makeResultsRows();
  }

  protected function makeResultsRows()
  {
    $usedPks = [];

    $rows = $this->db()->query('SELECT * FROM [e10doc_reporting_calcReportsRowsSD] WHERE [report] = %i', $this->calcReportNdx, ' ORDER BY [rowOrder]');
    foreach ($rows as $r)
    {
      $rowSDRecData = $r->toArray();
      $srcRowData = Json::decode($rowSDRecData['srcRowData']);

      $this->rowContents = [];
      $this->numbers = new \Shipard\Utils\Numbers($this->app());
      $scInfoSrcHead = $this->tableCalcReports->subColumnsInfo ($this->calcReportRecData, 'srcHeaderData');
      $this->numbers->addSubColumns ('src', 'all', $scInfoSrcHead, $this->srcHeaderData);
      $scInfoSrcRows = $this->tableCalcReportsRowsSD->subColumnsInfo ($this->calcReportRecData, 'srcRowData');
      $this->numbers->addSubColumns ('src', 'flat', $scInfoSrcRows, $this->srcRowData);

      $resRowData = $this->makeResultsRow($rowSDRecData, $srcRowData);

      $exist = $this->db()->query('SELECT * FROM [e10doc_reporting_calcReportsResults] WHERE [report] = %i', $this->calcReportNdx,
                ' AND [workOrder] = %i', $r['workOrder'])->fetch();

      if ($exist)
      {
        $update = $resRowData['recData'];
        $update['resData'] = Json::lint($resRowData['resData']);
        $update['resContent'] = Json::lint($resRowData['resContent']);
        $this->db()->query('UPDATE [e10doc_reporting_calcReportsResults] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);

        $usedPks[] = $exist['ndx'];
      }
      else
      {
        $insert = $resRowData['recData'];
        $insert['report'] = $this->calcReportNdx;
        $insert['resData'] = Json::lint($resRowData['resData']);
        $insert['resContent'] = Json::lint($resRowData['resContent']);

        $this->db()->query('INSERT INTO [e10doc_reporting_calcReportsResults] ', $insert);
        $newRowNdx = intval($this->db()->getInsertId());
        $usedPks[] = $newRowNdx;
      }
    }

    $this->db()->query('DELETE FROM [e10doc_reporting_calcReportsResults] WHERE [report] = %i', $this->calcReportNdx, ' AND [ndx] NOT IN %in', $usedPks);
    //$this->db()->query('DELETE FROM [e10doc_reporting_calcReportsResults] WHERE [report] = %i', 0);
    //error_log("_____".\dibi::$sql);
  }

  protected function makeResultsRow($rowSDRecData, $srcRowData)
  {
    $resRowData = [
      'recData' => [
        'resType' => 1, // row
        'rowOrder' => 10000 + $rowSDRecData['rowOrder'],
        'workOrder' => $rowSDRecData['workOrder'],
        'title' => $rowSDRecData['title'],
      ],
      'resData' => [],
      'resContent' => [],
    ];

    //$resRowData['resData']['amount_cost_SV'] = 123.45;


    $this->flatWorkOrderRecData = $this->app()->loadItem($rowSDRecData['workOrder'], 'e10mnf.core.workOrders');
    $this->flatWorkOrderRecDataCfg = Json::decode($this->flatWorkOrderRecData['vdsData']);

    $this->flatAreaRatio = $this->flatWorkOrderRecDataCfg['totalAreaRatio'];
    $this->flatAreaRatioFlat = 9999999;
    $this->flatAreaRatioBuilding = 9999999;

    $fap = explode('/', $this->flatAreaRatio);
    $this->flatAreaRatioFlat = intval($fap[0] ?? 999999);
    $this->flatAreaRatioBuilding = intval($fap[1] ?? 999999);


    $this->numbers->addSection('res_flat', 'Vyúčtování bytu');

    $this->makeResultsRow_WaterCold($rowSDRecData, $srcRowData, $resRowData);
    $this->makeResultsRow_WaterWarm($rowSDRecData, $srcRowData, $resRowData);
    $this->makeResultsRow_ElectricityCommon($rowSDRecData, $srcRowData, $resRowData);
    $this->makeResultsRow_Insurance($rowSDRecData, $srcRowData, $resRowData);
    $this->makeResultsRow_Administration($rowSDRecData, $srcRowData, $resRowData);

    $this->makeResultsRow_FlatRecap($rowSDRecData, $srcRowData, $resRowData);
    $this->makeResultsRow_FlatInfo($rowSDRecData, $srcRowData, $resRowData);

    $this->makeResultsRow_Meters($rowSDRecData, $srcRowData, $resRowData);

    $resRowData['resContent'][] = $this->rowContents['flat_info'];
    $resRowData['resContent'][] = $this->rowContents['flat_persons'];
    $resRowData['resContent'][] = $this->rowContents['flat_recap'];
    $resRowData['resContent'][] = $this->rowContents['flat_recap_pay'];

    $resRowData['resContent'][] = $this->rowContents['recap_meters'];
    $resRowData['resContent'][] = $this->rowContents['cold_water'];
    $resRowData['resContent'][] = $this->rowContents['warm_water'];
    $resRowData['resContent'][] = $this->rowContents['electricity_common'];
    $resRowData['resContent'][] = $this->rowContents['insurance'];
    $resRowData['resContent'][] = $this->rowContents['administration'];

    $this->makeResultsRow_Advances($rowSDRecData, $srcRowData, $resRowData);

    return $resRowData;
  }

  protected function makeResultsRow_FlatInfo($rowSDRecData, $srcRowData, &$resRowData)
  {
    $this->flatInfo = new \e10pro\condo\libs\FlatInfo($this->app());
    $this->flatInfo->setWorkOrder($rowSDRecData['rowOrder']);
    $this->flatInfo->loadInfo();

    $contentTitle = ['text' => $this->partMarks['flat_info'].'. '.'Informace o bytové jednotce', 'class' => 'h3'];
    foreach ($this->flatInfo->data['vdsContent'] as $cc)
    {
      $cc['params'] = ['hideHeader' => 1, ];
      $cc['title'] = $contentTitle;
      $this->rowContents['flat_info'] = $cc;

      break;
    }


    if ($this->flatInfo->data['personsList'])
    {
      $contentTitlePersons = ['text' => $this->partMarks['flat_persons'].'. '.'Kontaktní údaje', 'class' => 'h3'];
      $cc = $this->flatInfo->data['personsList'];
      unset($cc['pane']);
      $cc['title'] = $contentTitlePersons;
      $this->rowContents['flat_persons'] = $cc;
    }
  }

  protected function makeResultsRow_FlatRecap($rowSDRecData, $srcRowData, &$resRowData)
  {
    $finalAmount = 0;

    $ct = [];

    $r = [
      'mark' => $this->partMarks['cold_water'],
      'title' => 'Studená voda',
      'flat_cost' => $this->numbers->getMoney('res_flat_water_cold_cost'),
      'flat_advance' => $this->numbers->getMoney('res_flat_water_cold_advance'),
      'flat_balance' => $this->numbers->getMoney('res_flat_water_cold_balance'),
    ];
    $ct [] = $r;
    $finalAmount += $r['flat_balance'];


    $r = [
      'mark' => $this->partMarks['warm_water'].'₁',
      'title' => 'Studená voda v TUV',
      'flat_cost' => $this->numbers->getMoney('res_flat_water_warm_cold_cost'),
      'flat_advance' => $this->numbers->getMoney('res_flat_water_warm_cold_advance'),
      'flat_balance' => $this->numbers->getMoney('res_flat_water_warm_cold_balance'),
    ];
    $ct [] = $r;
    $finalAmount += $r['flat_balance'];

    $r = [
      'mark' => $this->partMarks['warm_water'].'₂',
      'title' => 'Ohřev vody (plyn)',
      'flat_cost' => $this->numbers->getMoney('res_flat_water_heating_total_cost'),
      'flat_advance' => $this->numbers->getMoney('res_flat_water_heating_advance'),
      'flat_balance' => $this->numbers->getMoney('res_flat_water_heating_balance'),
    ];
    $ct [] = $r;
    $finalAmount += $r['flat_balance'];

    $r = [
      'mark' => $this->partMarks['electricity_common'],
      'title' => 'Společná elektřina',
      'flat_cost' => $this->numbers->getMoney('res_flat_electricity_common_cost'),
      'flat_advance' => $this->numbers->getMoney('res_flat_electricity_common_advance'),
      'flat_balance' => $this->numbers->getMoney('res_flat_electricity_common_balance'),
    ];
    $ct [] = $r;
    $finalAmount += $r['flat_balance'];

    $r = [
      'mark' => $this->partMarks['insurance'],
      'title' => 'Pojištění domu',
      'flat_cost' => $this->numbers->getMoney('res_flat_insurance_cost'),
      'flat_advance' => $this->numbers->getMoney('res_flat_insurance_advance'),
      'flat_balance' => $this->numbers->getMoney('res_flat_insurance_balance'),
    ];
    $ct [] = $r;
    $finalAmount += $r['flat_balance'];

    $r = [
      'mark' => $this->partMarks['administration'],
      'title' => 'Správa domu',
      'flat_cost' => $this->numbers->getMoney('res_flat_administration_cost'),
      'flat_advance' => $this->numbers->getMoney('res_flat_administration_advance'),
      'flat_balance' => $this->numbers->getMoney('res_flat_administration_balance'),
    ];
    $ct [] = $r;
    $finalAmount += $r['flat_balance'];

    $resRowData['recData']['finalAmount'] = $finalAmount;

    $contentTitle = ['text' => $this->partMarks['flat_recap'].'. '.'Rekapitulace vyúčtování služeb a energií', 'class' => 'h3'];
    $contentHeader = [
      'mark' => '|#',
      'title' => 'Věc',
      'flat_advance' => '+Zálohy',
      'flat_cost' => '+Cena za byt',
      'flat_balance' => '+Přeplatek (+) / nedoplatek (-)',
    ];
    $content = [
      'type' => 'table', 'table' => $ct, 'header' => $contentHeader, 'title' => $contentTitle,
      //'params' => ['tableClass' => 'pageBreakAfter'],
    ];

    $this->rowContents['flat_recap'] = $content;


    $info = [];
    $info[] = ['code' => "<div style='margin-top: 20pt; padding: 4pt; border-left: 4pt solid black;' class='pageBreakAfter'>"];
    if ($finalAmount < 0.0)
		{
      $bankAccountNdx = key($this->app()->cfgItem ('e10doc.bankAccounts'));
      $bankAccount = $this->app()->cfgItem ('e10doc.bankAccounts.'.$bankAccountNdx);
			$info[] = ['text' => 'Výsledný nedoplatek ve výši '.Utils::nf(abs($finalAmount), 2).' prosím uhraďte:', 'class' => 'block'];
      $info[] = ['text' => '- bankovní účet: '.$bankAccount['bankAccount'], 'class' => 'block'];
      $info[] = ['text' => '- variabilní symbol: '.$this->flatWorkOrderRecData['symbol1'], 'class' => 'block'];
    }
		elseif ($finalAmount > 0.0)
		{
			$info[] = ['text' => 'Výsledný přeplatek ve výši '.Utils::nf($finalAmount, 2).' Vám bude uhrazen během několika dnů.', 'class' => ''];
		}
    $info[] = ['code' => "</div>"];
    $contentPay = ['type' => 'line', 'line' => $info, 'class' => 'pageBreakAfter'];
    $this->rowContents['flat_recap_pay'] = $contentPay;
  }

  protected function makeResultsRow_Meters($rowSDRecData, $srcRowData, &$resRowData)
  {
    // -- meters
    $mve = new \e10pro\meters\libs\MetersValues ($this->app());
    $mve->setQueryParam('workOrder', $rowSDRecData['workOrder']);
    $mve->setQueryParam('dateBegin', $this->calcReportRecData['dateBegin']);
    $mve->setQueryParam('dateEnd', $this->calcReportRecData['dateEnd']);
    $mve->load();

    $contentTitle = ['text' => $this->partMarks['recap_meters'].'. '.'Odečty měřičů', 'class' => 'h3'];
    $cc = $mve->data['contents'][0];
    $cc['title'] = $contentTitle;

    $this->rowContents['recap_meters'] = $cc;

    unset ($mve);
  }

  protected function makeResultsRow_Advances($rowSDRecData, $srcRowData, &$resRowData)
  {
    $contentTable = [];
    $contentHeader = ['title' => 'Věc', 'total' => '+Celkem'];

    $av = new \e10doc\reporting\libs\AccValues($this->app());
    $av->setQueryParam('workOrder', $rowSDRecData['workOrder']);
    $av->setQueryParam('dateBegin', $this->calcReportRecData['dateBegin']);
    $av->setQueryParam('dateEnd', $this->calcReportRecData['dateEnd']);


    foreach ($this->advancesList as $advanceId => $advance)
    {
      $sum = $av->loadAccountSum($advance['account']);
      $contentTable[$advanceId] = ['title' => $advance['title'], 'total' => $sum['sumAmount']];
    }

    $q = [];
    array_push ($q, ' SELECT fm.* ');
    array_push ($q, ' FROM [e10doc_base_fiscalmonths] AS fm');
		array_push ($q, ' WHERE fm.[fiscalYear] = %i', $this->calcReportRecData['fiscalYear']);
    array_push ($q, ' AND fm.fiscalType = %i', 0);
    array_push ($q, ' ORDER BY fm.[start], fm.ndx');
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->makeResultsRow_Advances_OneItem($r['start'], $r['end'], $rowSDRecData['workOrder'], $contentTable, $contentHeader);
    }

    $contentTitle = ['text' => $this->partMarks['recap_advances'].'. '.'Rekapitulace záloh', 'class' => 'h2'];
    $content = ['type' => 'table', 'table' => $contentTable, 'header' => $contentHeader, 'title' => $contentTitle];

    $resRowData['resContent'][] = $content;
  }

  protected function makeResultsRow_Advances_OneItem($dateBegin, $dateEnd, $workOrderNdx, &$contentTable, &$contentHeader)
  {
    $av = new \e10doc\reporting\libs\AccValues($this->app());
    $av->setQueryParam('workOrder', $workOrderNdx);
    $av->setQueryParam('dateBegin', $dateBegin);
    $av->setQueryParam('dateEnd', $dateEnd);

    $colId = $dateBegin->format('Ym');

    if (!isset($contentHeader[$colId]))
    {
      $contentHeader[$colId] = '+'.$dateBegin->format('m');
    }

    foreach ($this->advancesList as $advanceId => $advance)
    {
      $sum = $av->loadAccountSum($advance['account']);
      $contentTable[$advanceId][$colId] = $sum['sumAmount'];
    }
  }

  protected function makeResultsRow_WaterCold($rowSDRecData, $srcRowData, &$resRowData)
  {
    $this->numbers->addSectionPart('res_flat', 'cold_water', $this->partMarks['cold_water'], 'Studená voda');

    $this->numbers->addNumber('res_flat_water_cold_quantity', 'res_flat', 'cold_water', 'Odebrané množství studené vody v bytě', $srcRowData['flat_water_cold_quantity'], $this->unitM3, 3);
    $this->numbers->addNumber('res_all_water_cold_quantity', 'res_flat', 'cold_water', 'Odebrané množství vody v celém domě', $this->srcHeaderData['all_water_cold_quantity_meters'], $this->unitM3, 3);

    $waterUnitPrice = round($this->srcHeaderData['all_water_cold_costs'] / $this->srcHeaderData['all_water_cold_quantity_meters'], 4);


    $this->numbers->addMoney('res_all_water_cold_cost', 'res_flat', 'cold_water', 'Náklady na vodu v celém domě', $this->srcHeaderData['all_water_cold_costs']);
    $this->numbers->addNumberNote('res_all_water_cold_cost', 'cena za 1 ㎥ vody ≐ '.Utils::nf($waterUnitPrice, 4).' Kč');

    $flatWaterCost = round(($srcRowData['flat_water_cold_quantity'] / $this->srcHeaderData['all_water_cold_quantity_meters']) * $this->srcHeaderData['all_water_cold_costs'], 2);
    $this->numbers->addMoney('res_flat_water_cold_cost', 'res_flat', 'cold_water', 'Výsledná cena za odebranou vodu', $flatWaterCost);
    $this->numbers->addNumberNote('res_flat_water_cold_cost',
        '([(res_flat_water_cold_quantity)] / [(res_all_water_cold_quantity)]) × [(res_all_water_cold_cost)] = [(res_flat_water_cold_cost)]');


    $this->numbers->addMoney('res_flat_water_cold_advance', 'res_flat', 'cold_water', 'Zaplatili jste na zálohách', $srcRowData['flat_water_cold_advance']);



    $flatWaterBalance = round($srcRowData['flat_water_cold_advance'] - $flatWaterCost, 2);
    $flatWaterBalanceWordRes = ($flatWaterBalance > 0.0) ? 'přeplatek' : 'nedoplatek';
    $this->numbers->addMoney('res_flat_water_cold_balance', 'res_flat', 'cold_water', 'Výsledek: '.$flatWaterBalanceWordRes, $flatWaterBalance);
    $this->numbers->addNumberNote('res_flat_water_cold_balance',
        '[(res_flat_water_cold_advance)] - [(res_flat_water_cold_cost)] = [(res_flat_water_cold_balance)]');



    $content = $this->numbers->partContentTable('res_flat', 'cold_water');
    $this->rowContents['cold_water'] = $content;

    $resRowData['resData']['res_flat_water_cold_cost'] = $flatWaterCost;
    $resRowData['resData']['res_flat_water_cold_balance'] = $flatWaterBalance;
  }

  protected function makeResultsRow_WaterWarm($rowSDRecData, $srcRowData, &$resRowData)
  {
    $flatsCount = 12;

    $this->numbers->addSectionPart('res_flat', 'warm_water', $this->partMarks['warm_water'], 'Teplá voda ');

    if (1)
    {
      $this->numbers->addString('res_flat_water_area_ration', 'res_flat', 'warm_water', 'Podíl podlahové plochy', $this->flatAreaRatio);
    }

    $this->numbers->addNumber('res_flat_water_warm_quantity', 'res_flat', 'warm_water', 'Odebrané množství teplé vody v bytě', $srcRowData['flat_water_warm_quantity'], $this->unitM3, 3);
    $this->numbers->addNumber('res_all_water_cold_quantity_ww', 'res_flat', 'warm_water', 'Odebrané množství vody v celém domě', $this->srcHeaderData['all_water_cold_quantity_meters'], $this->unitM3, 3);

    $this->numbers->addNumber('res_all_water_warm_quantity', 'res_flat', 'warm_water', 'Celková spotřeba teplé vody v domě', $this->srcHeaderData['all_water_warm_quantity'], $this->unitM3, 3);

    $waterUnitPrice = round($this->srcHeaderData['all_water_cold_costs'] / $this->srcHeaderData['all_water_cold_quantity_meters'], 4);
    $this->numbers->addMoney('res_all_water_cold_cost_ww', 'res_flat', 'warm_water', 'Náklady na vodu v celém domě', $this->srcHeaderData['all_water_cold_costs']);
    $this->numbers->addNumberNote('res_all_water_cold_cost_ww', 'cena za 1 ㎥ vody ≐ '.Utils::nf($waterUnitPrice, 4).' Kč');


    $flatWaterCost = round(($srcRowData['flat_water_warm_quantity'] / $this->srcHeaderData['all_water_cold_quantity_meters']) * $this->srcHeaderData['all_water_cold_costs'], 2);
    $this->numbers->addMoney('res_flat_water_warm_cold_cost', 'res_flat', 'warm_water', 'Cena za odebranou vodu v TUV', $flatWaterCost);
    $this->numbers->addNumberNote('res_flat_water_warm_cold_cost',
        '([(res_flat_water_warm_quantity)] / [(res_all_water_cold_quantity_ww)]) × [(res_all_water_cold_cost_ww)] = [(res_flat_water_warm_cold_cost)]');

    $this->numbers->addMoney('res_flat_water_warm_cold_advance', 'res_flat', 'warm_water', 'Za SV v TUV jste na zálohách zaplatili', $srcRowData['flat_water_cold_warm_advance']);

    $flatWaterBalance = round($srcRowData['flat_water_cold_warm_advance'] - $flatWaterCost, 2);
    $flatWaterBalanceWordRes = ($flatWaterBalance > 0.0) ? 'přeplatek' : 'nedoplatek';
    $this->numbers->addMoney('res_flat_water_warm_cold_balance', 'res_flat', 'warm_water', 'Výsledek: '.$flatWaterBalanceWordRes, $flatWaterBalance);
    $this->numbers->addNumberNote('res_flat_water_warm_cold_balance',
        '[(res_flat_water_warm_cold_advance)] - [(res_flat_water_warm_cold_cost)] = [(res_flat_water_warm_cold_balance)]');

    // -- warm heating
    $this->numbers->addMoney('res_all_water_heating_cost', 'res_flat', 'warm_water', 'Celkové náklady na ohřev vody (plyn)', $this->srcHeaderData['all_water_heating_costs_gas']);

    $coefBase = 30;
    $coefConsumption = 100 - $coefBase;
    $costTotal = $this->srcHeaderData['all_water_heating_costs_gas'];
    $costBaseAll = round($costTotal * ($coefBase / 100), 2);
    $costConsumptionAll = round($costTotal - $costBaseAll, 2);

    $this->numbers->addMoney('res_all_water_heating_base_cost', 'res_flat', 'warm_water', '  → základní složka '.$coefBase.'%', $costBaseAll);
    $this->numbers->addNumberNote('res_all_water_heating_base_cost',
        '[(res_all_water_heating_cost)] × ('.$coefBase.' / 100) = [(res_all_water_heating_base_cost)]');

    $this->numbers->addMoney('res_all_water_heating_consumption_cost', 'res_flat', 'warm_water', '  → spotřební složka '.$coefConsumption.'%', $costConsumptionAll);
    $this->numbers->addNumberNote('res_all_water_heating_consumption_cost',
        '[(res_all_water_heating_cost)] - [(res_all_water_heating_base_cost)] = [(res_all_water_heating_consumption_cost)]');

    if (0)
    {
      $costBaseFlat = round($costBaseAll / $flatsCount, 2);
      $this->numbers->addMoney('res_flat_water_heating_base_cost', 'res_flat', 'warm_water', 'Cena za základní složku', $costBaseFlat);
      $this->numbers->addNumberNote('res_flat_water_heating_base_cost',
          '[(res_all_water_heating_base_cost)] / '.$flatsCount.' = [(res_flat_water_heating_base_cost)]');
    }
    else
    {
      $costBaseFlat = round($costBaseAll * ($this->flatAreaRatioFlat / $this->flatAreaRatioBuilding), 2);
      $this->numbers->addMoney('res_flat_water_heating_base_cost', 'res_flat', 'warm_water', 'Cena za základní složku', $costBaseFlat);
      $this->numbers->addNumberNote('res_flat_water_heating_base_cost',
          '[(res_all_water_heating_base_cost)] × ([(res_flat_water_area_ration)]) = [(res_flat_water_heating_base_cost)]');
    }

    $costConsumptionFlat = round(($srcRowData['flat_water_warm_quantity'] / $this->srcHeaderData['all_water_warm_quantity']) * $costConsumptionAll, 2);
    $this->numbers->addMoney('res_flat_water_heating_consumption_cost', 'res_flat', 'warm_water', 'Cena za spotřební složku', $costConsumptionFlat);
    $this->numbers->addNumberNote('res_flat_water_heating_consumption_cost',
        '([(res_flat_water_warm_quantity)] / [(res_all_water_warm_quantity)]) × [(res_all_water_heating_consumption_cost)] = [(res_flat_water_heating_consumption_cost)]');


    $costHeatingFlat = round($costBaseFlat + $costConsumptionFlat, 2);
    $this->numbers->addMoney('res_flat_water_heating_total_cost', 'res_flat', 'warm_water', 'Celková cena za ohřev vody ', $costHeatingFlat);
    $this->numbers->addNumberNote('res_flat_water_heating_total_cost',
        '[(res_flat_water_heating_base_cost)] + [(res_flat_water_heating_consumption_cost)] = [(res_flat_water_heating_total_cost)]');

    $this->numbers->addMoney('res_flat_water_heating_advance', 'res_flat', 'warm_water', 'Za ohřev vody jste na zálohách zaplatili', $srcRowData['flat_water_heating_advance']);
    $flatWaterHeatingBalance = round($srcRowData['flat_water_heating_advance'] - $costHeatingFlat, 2);
    $flatWaterHeatingBalanceWordRes = ($flatWaterHeatingBalance > 0.0) ? 'přeplatek' : 'nedoplatek';
    $this->numbers->addMoney('res_flat_water_heating_balance', 'res_flat', 'warm_water', 'Výsledek: '.$flatWaterHeatingBalanceWordRes, $flatWaterHeatingBalance);
    $this->numbers->addNumberNote('res_flat_water_heating_balance',
        '[(res_flat_water_heating_advance)] - [(res_flat_water_heating_total_cost)] = [(res_flat_water_heating_balance)]');



    // -- create content
    $content = $this->numbers->partContentTable('res_flat', 'warm_water');
    $content['params']['tableClass'] = 'pageBreakAfter';

    $this->rowContents['warm_water'] = $content;

    $resRowData['resData']['res_flat_water_warm_cold_cost'] = $flatWaterCost;
    $resRowData['resData']['res_flat_water_warm_cold_balance'] = $flatWaterBalance;

    $resRowData['resData']['res_flat_water_heating_cost'] = $costHeatingFlat;
    $resRowData['resData']['res_flat_water_heating_balance'] = $flatWaterHeatingBalance;
  }

  protected function makeResultsRow_ElectricityCommon($rowSDRecData, $srcRowData, &$resRowData)
  {
    $this->numbers->addSectionPart('res_flat', 'electricity_common', $this->partMarks['electricity_common'], 'Společná elektřina');
    $flatsCount = 12;

    $this->numbers->addMoney('res_all_electricity_common_costs', 'res_flat', 'electricity_common', 'Náklady na společnou elektřinu', $this->srcHeaderData['all_electricity_common_costs']);

    $costFlat = round($this->srcHeaderData['all_electricity_common_costs'] / $flatsCount, 2);
    $this->numbers->addMoney('res_flat_electricity_common_cost', 'res_flat', 'electricity_common', 'Váš podíl na společné elektřině', $costFlat);
    $this->numbers->addNumberNote('res_flat_electricity_common_cost',
        '[(res_all_electricity_common_costs)] / '.$flatsCount.' = [(res_flat_electricity_common_cost)]');

    $this->numbers->addMoney('res_flat_electricity_common_advance', 'res_flat', 'electricity_common', 'Za společnou elektřinu jste na zálohách zaplatili', $srcRowData['flat_electricity_common_advance']);
    $flatBalance = round($srcRowData['flat_electricity_common_advance'] - $costFlat, 2);
    $flatBalanceWordRes = ($flatBalance > 0.0) ? 'přeplatek' : 'nedoplatek';
    $this->numbers->addMoney('res_flat_electricity_common_balance', 'res_flat', 'electricity_common', 'Výsledek: '.$flatBalanceWordRes, $flatBalance);
    $this->numbers->addNumberNote('res_flat_electricity_common_balance',
        '[(res_flat_electricity_common_advance)] - [(res_flat_electricity_common_cost)] = [(res_flat_electricity_common_balance)]');


    $content = $this->numbers->partContentTable('res_flat', 'electricity_common');
    $this->rowContents['electricity_common'] = $content;

    $resRowData['resData']['res_flat_electricity_common_cost'] = $costFlat;
    $resRowData['resData']['res_flat_electricity_common_balance'] = $flatBalance;
  }

  protected function makeResultsRow_Insurance($rowSDRecData, $srcRowData, &$resRowData)
  {
    $this->numbers->addSectionPart('res_flat', 'insurance', $this->partMarks['insurance'], 'Pojištění domu');
    $flatsCount = 12;

    $this->numbers->addMoney('res_all_insurance_costs', 'res_flat', 'insurance', 'Náklady na pojištění domu', $this->srcHeaderData['all_insurance_costs']);
    $costFlat = round($this->srcHeaderData['all_insurance_costs'] / $flatsCount, 2);
    $this->numbers->addMoney('res_flat_insurance_cost', 'res_flat', 'insurance', 'Váš podíl na pojištění domu', $costFlat);
    $this->numbers->addNumberNote('res_flat_insurance_cost',
        '[(res_all_insurance_costs)] / '.$flatsCount.' = [(res_flat_insurance_cost)]');

    $this->numbers->addMoney('res_flat_insurance_advance', 'res_flat', 'insurance', 'Za pojištění domu jste na zálohách zaplatili', $srcRowData['flat_insurance_advance']);
    $flatBalance = round($srcRowData['flat_insurance_advance'] - $costFlat, 2);
    $flatBalanceWordRes = ($flatBalance > 0.0) ? 'přeplatek' : 'nedoplatek';
    $this->numbers->addMoney('res_flat_insurance_balance', 'res_flat', 'insurance', 'Výsledek: '.$flatBalanceWordRes, $flatBalance);
    $this->numbers->addNumberNote('res_flat_insurance_balance',
        '[(res_flat_insurance_advance)] - [(res_flat_insurance_cost)] = [(res_flat_insurance_balance)]');

    $content = $this->numbers->partContentTable('res_flat', 'insurance');
    $this->rowContents['insurance'] = $content;

    $resRowData['resData']['res_flat_insurance_cost'] = $costFlat;
    $resRowData['resData']['res_flat_insurance_balance'] = $flatBalance;
  }

  protected function makeResultsRow_Administration($rowSDRecData, $srcRowData, &$resRowData)
  {
    $this->numbers->addSectionPart('res_flat', 'administration', $this->partMarks['administration'], 'Správa domu');
    $flatsCount = 12;

    $this->numbers->addMoney('res_all_administration_costs', 'res_flat', 'administration', 'Náklady na správu domu', $this->srcHeaderData['all_administration_costs']);
    $costFlat = round($this->srcHeaderData['all_administration_costs'] / $flatsCount, 2);
    $this->numbers->addMoney('res_flat_administration_cost', 'res_flat', 'administration', 'Váš podíl na správě domu', $costFlat);
    $this->numbers->addNumberNote('res_flat_administration_cost',
        '[(res_all_administration_costs)] / '.$flatsCount.' = [(res_flat_administration_cost)]');
    $this->numbers->addMoney('res_flat_administration_advance', 'res_flat', 'administration', 'Za správu domu jste na zálohách zaplatili', $srcRowData['flat_administration_advance']);
    $flatBalance = round($srcRowData['flat_administration_advance'] - $costFlat, 2);
    $flatBalanceWordRes = ($flatBalance !== 0.0) ? (($flatBalance > 0.0) ? 'přeplatek' : 'nedoplatek') : 'bez přeplatku či nedoplatku';
    $this->numbers->addMoney('res_flat_administration_balance', 'res_flat', 'administration', 'Výsledek: '.$flatBalanceWordRes, $flatBalance);
    $this->numbers->addNumberNote('res_flat_administration_balance',
        '[(res_flat_administration_advance)] - [(res_flat_administration_cost)] = [(res_flat_administration_balance)]');


    $content = $this->numbers->partContentTable('res_flat', 'administration');
    $content['params']['tableClass'] = 'pageBreakAfter';

    $this->rowContents['administration'] = $content;

    $resRowData['resData']['res_flat_administration_cost'] = $costFlat;
    $resRowData['resData']['res_flat_administration_balance'] = $flatBalance;
  }

  public function doRebuild()
  {
    $this->prepareSrcRowsData();
    $this->savePreparedSrcData();

    $this->makeResults();
  }
}