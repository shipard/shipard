<?php

namespace e10doc\waster\libs;


use \Shipard\Utils\Utils;
use \Shipard\Utils\World;
use \e10pro\reports\waste_cz\libs\WasteReturnEngine;


/**
 * class ReportWasteReturns
 */
class ReportWasteReturns extends \e10doc\core\libs\reports\GlobalReport
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;
  var $persons = [];
  var $showUnits = -1;
  var $codeKindNdx = 0;

  var $wasteReturnNdx = 0;
  var $wasteReturnRecData = NULL;

  var $municipalityData = [];

  var $thisCountryNdx = 60;

  var $partners = [];
  var $wastes = [];

  var $hcStates = [];

  var $wasteHandlingCodes = NULL;

  var $oneWasteCode = '';

	public function init ()
	{
    $this->wasteHandlingCodes = $this->app()->cfgItem('e10doc.waster.handlingCodes', []);

    $enumWasteReturns = $this->wasteReturnsEnum();
    $this->addParam('switch', 'wasteReturn', ['title' => 'Hlášení', 'switch' => $enumWasteReturns, '__defaultValue' => 'all']);

    $ckEnum = $this->codesKindEnum();
    $this->addParam('switch', 'codeKind', ['title' => 'Druh', 'switch' => $ckEnum, 'radioBtn' => 1, '__defaultValue' => 'all']);

    if ($this->subReportId === 'report')
      $this->addParam('switch', 'showUnits', ['title' => 'Jednotka', 'switch' => ['1' => 'Tuny', '0' => 'kg'], 'radioBtn' => 1, 'defaultValue' => '1']);

		parent::init();

    $this->showUnits = intval($this->reportParams ['showUnits']['value'] ?? '1');

    if (!$this->codeKindNdx)
      $this->codeKindNdx = intval($this->reportParams ['codeKind']['value'] ?? 1);

    if (!$this->wasteReturnNdx)
      $this->wasteReturnNdx = intval($this->reportParams ['wasteReturn']['value'] ?? 0);
    $this->wasteReturnRecData = $this->app()->loadItem($this->wasteReturnNdx, 'e10doc.waster.wasteReturns');

    if (!$this->periodBegin)
    {
      $this->periodBegin = $this->wasteReturnRecData['dateFrom'];
      $this->periodEnd = $this->wasteReturnRecData['dateTo'];

      $this->setInfo('icon', 'reportMonthlyReport');
      //$this->setInfo('param', 'Období', $this->reportParams ['calendarPeriod']['activeTitle']);
    }
    else
    {
      $this->periodBegin = Utils::createDateTime($this->periodBegin);
      $this->periodEnd = Utils::createDateTime($this->periodEnd);
    }

    $this->setInfo('param', 'Hlášení', $this->wasteReturnRecData['title']);
  }

  function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Sum (); break;
			case 'report': $this->createContent_Report (); break;
			case 'report2': $this->createContent_Report2 (); break;
			case 'partners': $this->createContent_Partners (); break;
      case 'citizensCities': $this->createContent_Citizens (); break;
		}
	}

  public function createContent_Sum()
  {
    $q = [];

    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG, [rows].[dir], [rows].personType,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);
		array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].[dir], [rows].personType');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
      $wcId = 'W'.$r['wasteCodeNomenc'];

      if (!isset($data[$wcId]))
      {
        $data[$wcId] = [
          'wasteCode' => $r['itemId'],
          'wasteName' => $r['fullName'],
        ];
      }

      if ($r['dir'] == WasteReturnEngine::rowDirIn)
      {
        if ($r['personType'] == WasteReturnEngine::personTypeHuman)
          $data[$wcId]['quantityInH'] = $r['quantityKG'];
        elseif ($r['personType'] == WasteReturnEngine::personTypeCompany)
          $data[$wcId]['quantityInC'] = $r['quantityKG'];

        $data[$wcId]['quantityIn'] ??= 0.0;
        $data[$wcId]['quantityIn'] += $r['quantityKG'];
      }
      elseif ($r['dir'] == WasteReturnEngine::rowDirOut)
        $data[$wcId]['quantityOut'] = $r['quantityKG'];

			//$data[] = $item;
		}

		$h = [
      'wasteCode' => ' Kód odpadu',
      'wasteName' => 'Text',
      'quantityInH' => '+Příjem Občané',
      'quantityInC' => '+Příjem Firmy',
      'quantityIn' => '+Příjem CELKEM',
      'quantityOut' => '+Výdej',
    ];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

		$this->setInfo('title', 'Celkový přehled o pohybu odpadů');
    $this->setInfo('note', '1', 'Všechna množství jsou v kilogramech');
  }

  public function createContent_Report()
  {
    $data = [];

    $this->loadMunicipalityData();

    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirIn, $data); // in ops
    $this->createContent_Report_Load_WasteMoves(WasteReturnEngine::rowDirIn, $data); // in moves
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirIn, $data); // companies IN
    $this->createContent_Report_Load(1, WasteReturnEngine::rowDirIn, $data); // humans
    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirOut, $data); // out ops
    $this->createContent_Report_Load_WasteMoves(WasteReturnEngine::rowDirOut, $data); // out moves
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirOut, $data); // companies OUT

    $sum = [
      'wc' => ['in' => 0.0, 'out' => 0.0],
      'total' => ['in' => 0.0, 'out' => 0.0],
    ];

    $data = Utils::sortByOneKey($data, 'wasteCode', TRUE, TRUE, FALSE, SORT_STRING);

    $t = [];
    foreach ($data as $gid => $groupRows)
    {
      $header = [
        'wasteCode' => ['text' => $groupRows['wasteCode'], 'suffix' => $groupRows['wasteName']],
        '_options' => [
          'colSpan' => ['wasteCode' => 13],
          'class' => 'subheader',
        ]
      ];
      $header['_options']['beforeSeparator'] = 'separator';
      $t[] = $header;

      $stripCounter = 0;
      $sum ['wc'] = ['in' => 0.0, 'out' => 0.0];
      $rows = \e10\sortByOneKey($groupRows['rows'], 'order');
      $cntSubRows = count($rows);
      foreach ($rows as $row)
      {
        if (isset($row['quantityIn']))
        {
          $sum['total']['in'] += $row['quantityIn'];
          $sum['wc']['in'] += $row['quantityIn'];
        }
        if (isset($row['quantityOut']))
        {
          $sum['total']['out'] += $row['quantityOut'];
          $sum['wc']['out'] += $row['quantityOut'];
        }

        if (($row['rs'] ?? 0) === 1)
        {
          $hcCfg = $this->wasteHandlingCodes[$row['hc']] ?? NULL;
          if ($hcCfg)
          {
            $row['oid'] = $hcCfg['sn'];
            $row['_options']['colSpan']['oid'] = 9;
            $row['_options']['class'] = 'e10-row-this';
          }
        }

        if (isset($row['partnerId']))
        {
          $partner = $this->partners[$row['partnerId']] ?? NULL;
          if ($partner)
            $row['pid'] = $partner['number'];
        }

        if ($cntSubRows > 4 && $stripCounter % 2 && !isset($row['_options']['class']))
          $row['_options']['class'] = 'e10-bg-t9';

        $t[] = $row;
        $stripCounter++;
      }

      $sumRow = [
        'wasteCode' => 'CELKEM',
        'quantityIn' => $sum['wc']['in'], 'quantityOut' => $sum['wc']['out'],
        '_options' => ['class' => 'e10-bold e10-row-minus',]
      ];
      if (abs(round($sumRow['quantityIn'] - $sumRow['quantityOut'], 6)) < 0.000001)
        $sumRow['_options']['class'] = 'e10-bold e10-row-plus';

      $t[] = $sumRow;
    }

    $sumRow = [
      'wasteCode' => 'CELKEM', 'quantityIn' => $sum['total']['in'], 'quantityOut' => $sum['total']['out'],
      '_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator']
    ];
    $t[] = $sumRow;

		$h = [
      'wasteCode' => 'Kód odp.',
      'hc' => 'EK',
      'quantityIn' => ' Příjem '.(($this->showUnits === 1) ? '[t]' : '[kg]'),
      'quantityOut' => ' Výdej '.(($this->showUnits === 1) ? '[t]' : '[kg]'),
      'pid' => ' pid',
      'oid' => 'IČ',
      'pn' => 'Název partnera',
      'id1' => 'IČP',
      'id2' => 'IČZ',
      'id3' => 'IČOB',
      'id4' => 'IČZUJ',
      'street' => 'Ulice',
      'city' => 'Město',
      'zipCode' => 'PSČ',
    ];
		$this->addContent (
      [
        'type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE,
        'params' => ['tableClass' => 'e10-print-small', 'precision' => ($this->showUnits === 1) ? 6 : 2]
      ]);

    $this->setInfo('title', 'Roční hlášení o produkci a nakládání s odpady');
    $this->paperOrientation = 'landscape';

    $this->wastes = $data;
  }

  public function createContent_Report2()
  {
    $data = [];

    $this->loadMunicipalityData();

    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirIn, $data); // in ops
    $this->createContent_Report_Load_WasteMoves(WasteReturnEngine::rowDirIn, $data); // in moves
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirIn, $data); // companies IN
    $this->createContent_Report_Load(1, WasteReturnEngine::rowDirIn, $data); // humans
    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirOut, $data); // out ops
    $this->createContent_Report_Load_WasteMoves(WasteReturnEngine::rowDirOut, $data); // out moves
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirOut, $data); // companies OUT

    $data = Utils::sortByOneKey($data, 'wasteCode', TRUE, TRUE, FALSE, SORT_STRING);

    $sumData = $this->createContent_Report_Load_WasteSums($data);

    $t = [];
    $sum = [
      'wc' => ['in' => 0.0, 'out' => 0.0],
      'total' => ['in' => 0.0, 'out' => 0.0],
    ];

    //$this->hcStates[$wcc][$hcc]['in'] += $item['quantityIn'];

    foreach ($sumData as $gid => $groupRows)
    {
      $header = [
        'hc' => ['text' => $groupRows['wasteCode'], 'suffix' => $groupRows['wasteName']],
        '_options' => [
          'colSpan' => ['hc' => 3],
          'class' => 'subheader',
        ]
      ];
      $header['_options']['beforeSeparator'] = 'separator';
      $t[] = $header;


      $wcSum = [
        'quantityIn' => 0.0,
        'quantityOut' => 0.0,
      ];

      $quantityState = 0.0;

      $mainGLSums = [];//['in' => 0.0, 'out' => 0.0];

      $rows = \e10\sortByOneKey($groupRows['rows'], 'order', TRUE);
      foreach ($rows as $hc => $hcRow)
      {
        $mainLetter = $hc[0];
        if (!isset($mainGLSums[$mainLetter]))
          $mainGLSums[$mainLetter] = ['letter' => $mainLetter, 'in' => 0.0, 'out' => 0.0];

        $sumRow = [
          'hc' => $hc,
          // 'qs' => $hccData['in'] - $hccData['out'],
        ];

        if (isset($this->wasteHandlingCodes[$hc]))
          $sumRow['hc'] = $this->wasteHandlingCodes[$hc]['sn'];

        if ($hcRow['quantityIn'] ?? 0)
          $sumRow['quantityIn'] = $hcRow['quantityIn'];
        if ($hcRow['quantityOut'] ?? 0)
          $sumRow['quantityOut'] = $hcRow['quantityOut'];



        $wcSum['quantityIn'] += $sumRow['quantityIn'] ?? 0.0;
        $wcSum['quantityOut'] += $sumRow['quantityOut'] ?? 0.0;

        $mainGLSums[$mainLetter]['in'] += $sumRow['quantityIn'] ?? 0.0;
        $mainGLSums[$mainLetter]['out'] += $sumRow['quantityOut'] ?? 0.0;

        $quantityState += $sumRow['quantityIn'] ?? 0.0;
        $quantityState -= $sumRow['quantityOut'] ?? 0.0;
        $sumRow['qs'] = $quantityState;

        $t[] = $sumRow;
      }

      $sumRow = [
        'hc' => 'CELKEM:',
        'quantityIn' => $wcSum['quantityIn'],
        'quantityOut' => $wcSum['quantityOut'],
        '_options' => ['class' => 'subtotal',]
      ];
      if (abs(round($sumRow['quantityIn'] - $sumRow['quantityOut'], 6)) < 0.000001)
        $sumRow['_options']['class'] = ' e10-bold e10-row-plus';
      else
        $sumRow['_options']['class'] = ' e10-bold e10-row-minus';

      $t[] = $sumRow;

//---

      if (abs(round($sumRow['quantityIn'] - $sumRow['quantityOut'], 6)) >= 0.000001)
      {
        $wcc = $groupRows['wasteCode'];
        foreach ($this->hcStates[$wcc] as $hcc => $hccData)
        {
          if (abs(round($hccData['in'] - $hccData['out'], 6)) < 0.000001)
            continue;

          $item = [
            'hc' => $hcc.': '.round($sumRow['quantityIn'] - $sumRow['quantityOut'], 6).' / '.json_encode($hccData) . ' | '.json_encode($this->hcStates[$wcc]),
            //'quantityIn' => $hccData['in'],
            //'quantityOut' => $hccData['out'],
          ];

          $t[] = $item;
        }
      }

//---

      foreach ($mainGLSums as $mgls)
      {
        $letterRest = round($mgls['in'] - $mgls['out'], 6);
        $sumRow = [
          'hc' => [['text' => 'Zůstatek '.$mgls['letter'].':', 'class' => 'e10-italic']],
          'quantityIn' => $mgls['in'],
          'quantityOut' => $mgls['out'],
          'qs' => $letterRest,
        ];

        if (abs(round($sumRow['quantityIn'] - $sumRow['quantityOut'], 6)) >= 0.000001)
          $sumRow['_options']['cellClasses']['qs'] = 'e10-warning3';

        $addESQuantity = round($sumRow['quantityIn'] - $sumRow['quantityOut'], 6);
        $endStateHC = $mgls['letter'].'N5';
        if ($addESQuantity > 0.0)
        {
          $btn = [
            'text' => 'Přidat KS: '.$addESQuantity. ' t', 'docAction' => 'new', 'table' => 'e10doc.waster.wasteOps',
            'type' => 'button', 'actionClass' => 'btn btn-xs btn-success pull-right', 'icon' => 'system/actionAdd',
            'addParams' => '__opType=1&__wasteHandlingCodeSrc='.$endStateHC.'&__quantity='.$addESQuantity.'&__unit=t'.'&__date='.$this->periodEnd->format('Y-m-d').'&__wasteCodeNomencSrc='.$groupRows['wasteCodeNomenc'],
          ] ;
          $sumRow['hc'][] = $btn;
        }

        $qwop = [];
        array_push( $qwop, 'SELECT * FROM e10doc_waster_wasteOps');
        array_push( $qwop, ' WHERE 1');
        array_push( $qwop, ' AND [opType] = %i', 1);
        array_push( $qwop, ' AND [wasteHandlingCodeSrc] = %s', $endStateHC);
        array_push( $qwop, ' AND [wasteCodeNomencSrc] = %i', $groupRows['wasteCodeNomenc']);
        array_push( $qwop, ' AND [date] = %d', $this->periodEnd->format('Y-m-d'));
        $rowsWOp = $this->app()->db()->query ($qwop);
        foreach ($rowsWOp as $rwo)
        {
          $btn = [
            'text' => 'Upravit KS '.$endStateHC.': '.$rwo['quantity'].' t', 'docAction' => 'edit', 'table' => 'e10doc.waster.wasteOps', 'pk' => $rwo['ndx'],
            'type' => 'button', 'actionClass' => 'btn btn-xs btn-primary pull-right', 'icon' => 'system/actionOpen',
          ] ;
          $sumRow['hc'][] = $btn;
        }

        $t[] = $sumRow;
      }
    }

		$h = [
      'hc' => 'Evidenční kód nakládání',
      'quantityIn' => ' Příjem '.(($this->showUnits === 1) ? '[t]' : '[kg]'),
      'quantityOut' => ' Výdej '.(($this->showUnits === 1) ? '[t]' : '[kg]'),
      'qs' => ' Zůstatek '.(($this->showUnits === 1) ? '[t]' : '[kg]'),
    ];
		$this->addContent (
      [
        'type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE,
        'params' => ['tableClass' => 'e10-print-small default', 'precision' => ($this->showUnits === 1) ? 6 : 2]
      ]);

    $this->setInfo('title', 'Roční hlášení o produkci a nakládání s odpady');
    $this->paperOrientation = 'landscape';
  }

  public function createContent_Partners()
  {
    $data = [];

    $this->loadMunicipalityData();

    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirIn, $data); // in ops
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirIn, $data); // companies IN
    $this->createContent_Report_Load(1, WasteReturnEngine::rowDirIn, $data); // humans
    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirOut, $data); // out ops
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirOut, $data); // companies OUT

    $this->setInfo('title', 'Partneři');
    $this->paperOrientation = 'landscape';

		$h = [
      //'#' => '#',
      'number' => ' PID',
      'name' => 'Název',
      'ico' => ' IČO',
      'icp' => ' IČP',
      'icob' => 'IČOB',
      'icz' => 'IČZ',
      'orp' => ' ORP',
      'iczuj' => 'IČZUJ',
      'ulice' => 'Ulice',
      'cisloPopisne' => 'Čís.p.',
      'cisloOrientacni' => 'Čís.o.',
      'obec' => 'Obec',
      'psc' => 'PSČ',
    ];
		$this->addContent (
      [
        'type' => 'table', 'header' => $h, 'table' => $this->partners, 'main' => TRUE,
        'params' => ['tableClass' => 'e10-print-small default stripped']
      ]);
  }

  public function createContent_Report_Load($personType, $wasteDir, &$data)
  {
    if ($personType == 1)
    {
      $this->createContent_Report_Load_Municipality($data);
      return;
    }

    $q = [];

    if ($personType == 2) // companies
    {
      array_push ($q, 'SELECT [rows].person, [rows].personOffice, [rows].wasteCodeNomenc, [rows].wasteHandlingCode, [rows].[dir], [rows].[addressMode], [rows].[nomencCity],');
      array_push ($q, ' SUM([rows].quantityKG) as quantityKG,');
      array_push ($q, ' nomencItems.fullName, nomencItems.itemId,');
      array_push ($q, ' persons.fullName AS personFullName, addrs.saAdmUnit11Id,');
      array_push ($q, ' addrs.adrCity, addrs.adrStreet, addrs.adrZipCode, addrs.id1, addrs.id2');
      array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
      array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
      array_push ($q, ' LEFT JOIN [e10_persons_personsContacts] AS addrs ON [rows].personOffice = addrs.ndx');
      array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON [rows].person = persons.ndx');
    }
    else
    { // citizens
      array_push ($q, 'SELECT [rows].wasteCodeNomenc, [rows].[dir], [rows].wasteHandlingCode, [rows].natCityId, ');
      array_push ($q, ' SUM([rows].quantityKG) as quantityKG,');
      array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
      array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
      array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    }

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [rows].personType = %i', $personType);
    array_push ($q, ' AND [rows].[dir] = %i', $wasteDir);

    if ($this->oneWasteCode !== '')
      array_push ($q, ' AND nomencItems.itemId = %s', $this->oneWasteCode);

    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);

    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);

    if ($personType === 2)
    { // companies
      //[rows].quantityKG
      array_push ($q, ' AND [rows].[quantityKG] != 0');

		  array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].person, [rows].addressMode, [rows].personOffice, addrs.saAdmUnit11Id, [rows].[nomencCity], [rows].[dir], [rows].wasteHandlingCode');
      array_push ($q, ' ORDER BY [rows].wasteCodeNomenc, persons.fullName');
    }
    else
    { // citizens
      array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].[dir], [rows].wasteHandlingCode, [rows].natCityId');
      array_push ($q, ' ORDER BY [rows].wasteCodeNomenc');
    }

    $cnt = 0;
		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
      $gid = 'G'.$r['itemId'];
      $hcCfg = $this->wasteHandlingCodes[$r['wasteHandlingCode']] ?? NULL;

      $personOid = '';
      $personICOB = '';
      $pn = '';
      $order = $r['itemId'].'_'.'A'.'_'.($hcCfg['sortOrder'] ?? 999).'_';

      if ($personType == 2)
      { // companies
        $personOid = $this->personOid($r['person']);
        $personICOB = $this->personICOB($r['person']);
        $pn = $r['personFullName'];
        $order .= sprintf('%09d', $cnt);
      }
      else
      {
        $pn = 'OBČANÉ';
        $order .= '999999999';
      }

      $item = [
        'wasteCode' => $r['itemId'],
        'wasteCodeNomenc' => $r['wasteCodeNomenc'],
        'wasteName' => $r['fullName'],
        'hc' => $r['wasteHandlingCode'],
        'oid' => $personOid,
        'pn' => $pn,
        'order' => $order,
        'id3' => $personICOB,
      ];

      if ($r['dir'] == WasteReturnEngine::rowDirIn)
      {
        if ($this->showUnits === 1)
          $item['quantityIn'] = round($r['quantityKG'] / 1000, 6);
        else
          $item['quantityIn'] = $r['quantityKG'];
      }
      elseif ($r['dir'] == WasteReturnEngine::rowDirOut)
      {
        if ($this->showUnits === 1)
          $item['quantityOut'] = round($r['quantityKG'] / 1000, 6);
        else
        $item['quantityOut'] = $r['quantityKG'];
      }
      if ($personType === 1)
      { // citizens
        $item['id4'] = strval($r['natCityId']);
      }
      if ($personType === 2)
      {
        if ($r['addressMode'] === 0)
        { // office
          if ($r['personOffice'])
          {
            $item['city'] = $r['adrCity'];
            $item['street'] = $r['adrStreet'];
            $item['zipCode'] = str_replace(' ', '', $r['adrZipCode']);

            $item['id4'] = strval($r['saAdmUnit11Id']);
            $item['city'] = $this->cityById($r['saAdmUnit11Id']);

            if ((isset($r['id1']) && $r['id1'] !== ''))
            {
              $item['id1'] = $r['id1'];
              $item['icp'] = $r['id1'];
            }
            if ((isset($r['id2']) && $r['id2'] !== ''))
            {
              $item['id2'] = $r['id2'];
              $item['icz'] = $r['id2'];
            }

            if (($item['id1'] ?? '') === '' && ($item['id2'] ?? '') === '')
            {
              $item['id1'] = '1';
              $item['icp'] = '1';
            }
          }
          else
          {
            $addr = $this->personMainAddress($r['person']);
            if ($addr)
            {
              $item['city'] = $addr['adrCity'];
              $item['street'] = $addr['adrStreet'];
              $item['zipCode'] = str_replace(' ', '', $addr['adrZipCode']);
              $item['id1'] = '1';
              $item['icp'] = '1';
            }
          }
        }
        else
        { // city
          $admUnit11Data = $this->app()->loadItem($r['nomencCity'], 'e10.world.admUnits'); // ZUJ
          $zujId = strval($admUnit11Data['admUnitId'] ?? '!!!');
          $admUnit10Data = $this->app()->loadItem($admUnit11Data['admUnitOwner10'], 'e10.world.admUnits'); // ORP
          $orpId = strval($admUnit10Data['admUnitId'] ?? '!!!');

          $item['id1'] = [
            ['text' => 'ORP: '.$orpId, 'suffix' => $admUnit10Data['fullName'] ?? '!!!!', 'class' => ''],
            ['text' => 'ZUJ: '.$zujId, 'suffix' => $admUnit11Data['fullName'] ?? '!!!!', 'class' => ''],
          ];
          $item['id_orp'] = $orpId;
          $item['id4'] = $zujId;
        }
      }
      else
      {
        $item['isCity'] = 1;
      }

      $item['partnerId'] = $this->registerPartner($item);

      if (!isset($data[$gid]))
      {
        $data[$gid] = [
          'wasteCode' => $r['itemId'],
          'wasteCodeNomenc' => $r['wasteCodeNomenc'],
          'wasteName' => $r['fullName'],
          'rows' => [],
        ];
      }

      $wcc = $r['itemId'];
      $hcc = $item['hc'];
      $hcCfg = $this->wasteHandlingCodes[$hcc] ?? NULL;

      if ($wasteDir == WasteReturnEngine::rowDirIn)
      {
        if (!isset($this->hcStates[$wcc]))
        {
          $this->hcStates[$wcc] = [
            'C00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
            'A00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],
            'B00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
            'A10' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],
            ];
        }

        if (isset($hcCfg['initStateHC']))
          $this->hcStates[$wcc][$hcCfg['initStateHC']]['in'] += $item['quantityIn'];
        else
          $this->hcStates[$wcc][$hcc]['in'] += $item['quantityIn'];

        $data[$gid]['rows'][] = $item;
      }
      elseif ($wasteDir == WasteReturnEngine::rowDirOut)
      { // XX1
        $totalToOut = $item['quantityOut'];
        $restOut = $item['quantityOut'];
        foreach ($this->hcStates[$wcc] as $stateHC => &$states)
        {
          $available = $states['in'] - $states['out'];
          if ($available <= 0.0)
            continue;
          $thisOut = $totalToOut;
          if ($available <= $thisOut)
            $thisOut = $available;

          //error_log("###: `$wcc`/`$stateHC`: $totalToOut | $available | $thisOut / ".json_encode($this->hcStates[$wcc]));


          $states['out'] += $thisOut;
          $itemOut = $item;
          $itemOut['quantityOut'] = $thisOut;
          $itemOut['hc'] = $states['outHc'];
          $data[$gid]['rows'][] = $itemOut;
          $totalToOut -= $thisOut;
          if ($totalToOut <= 0.0)
            break;
        }
      }

      $cnt++;
		}
  }

  public function createContent_Report_Load_Municipality(&$data)
  {
    $cnt = 0;
		forEach ($this->municipalityData as $r)
		{
      $gid = 'G'.$r['wasteCode'];

      $hcCfg = $this->wasteHandlingCodes[$r['hc']] ?? NULL;
      $order = $r['wasteCode'].'_'.'A'.'_'.($hcCfg['sortOrder'] ?? 999).'_';

      $pn = 'OBČANÉ';
      $order .= '999999999';

      $item = [
        'wasteCode' => $r['wasteCode'],
        'wasteName' => 'odpad: '.$r['wasteCode'],
        'hc' => $r['hc'],
        'pn' => $pn,
        'order' => $order,
      ];

      $item['quantityIn'] = round($r['quantity'], 6);
      $item['id4'] = strval($r['iczuj']);
      $item['isCity'] = 1;
      $item['partnerId'] = $this->registerPartner($item);
      $wcc = $r['wasteCode'];
      $hcc = $r['hc'];
      $hcCfg = $this->wasteHandlingCodes[$hcc] ?? NULL;

      if (!isset($data[$gid]))
      {
        $data[$gid] = [
          'wasteCode' => $r['wasteCode'],
          'wasteCodeNomenc' => $r['wasteCodeNomenc'],
          'wasteName' => $r['wasteName'],
          'rows' => [],
        ];
      }

      if (!isset($this->hcStates[$wcc]))
      {
        $this->hcStates[$wcc] = [
          'C00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
          'A00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],
          'B00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
          //'BN30' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
          'A10' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],

          //'XN3' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
          ];
      }
      if (isset($hcCfg['initStateHC']))
        $this->hcStates[$wcc][$hcCfg['initStateHC']]['in'] += $item['quantityIn'];
      else
        $this->hcStates[$wcc][$hcc]['in'] += $item['quantityIn'];

      $data[$gid]['rows'][] = $item;
      $cnt++;
		}
  }

  protected function registerPartner(&$item)
  {
    $ico = $item['oid'] ?? '';
    $icz = $item['icz'] ?? '';
    $icob = $item['id3'] ?? '';
    $icp = $item['icp'] ?? '';
    $iczuj = strval($item['id4'] ?? '');

    $partnerId = 'P'.$ico.'_';
    if ($icz !== '')
      $partnerId .= $icz;
    elseif ($icob !== '')
      $partnerId .= $icob;
    elseif ($icp !== '')
      $partnerId .= $icp;
    elseif ($iczuj !== '')
      $partnerId .= $iczuj;

    if (isset($this->partners[$partnerId]))
    {
      if (isset($item['isCity']))
      {
        $partner = $this->partners[$partnerId];
        $item['city'] = $partner['obec'];
        $item['pn'] = 'Občané obce '.$partner['obec'];
      }
      return $partnerId;
    }

    $partnerNumber = count($this->partners) + 1;
    $partner = [
      'number' => $partnerNumber,
      'name' => $item['pn'],
    ];

    if (isset($item['oid']) && $item['oid'] !== '')
      $partner['ico'] = $item['oid'];

    if (isset($item['icp']) && $item['icp'] !== '')
      $partner['icp'] = $item['icp'];

    if (isset($item['icz']) && $item['icz'] !== '')
      $partner['icz'] = $item['icz'];

    if (isset($item['id_orp']) && $item['id_orp'] !== '')
      $partner['orp'] = $item['id_orp'];

    if (isset($item['id3']) && $item['id3'] !== '')
      $partner['icob'] = $item['id3'];

    if (isset($item['city']) && $item['city'] !== '')
      $partner['obec'] = $item['city'];

    if (isset($item['zipCode']) && $item['zipCode'] !== '')
      $partner['psc'] = $item['zipCode'];

    if (isset($item['id4']) && $item['id4'] !== '')
      $partner['iczuj'] = $item['id4'];
    else
    {
      //$partner['iczuj'] = strval($this->natCityId($item['city'] ?? ''));
    }
    if (isset($item['street']) && $item['street'] !== '')
    {
      $sp = explode(' ', $item['street']);
      if (count($sp) > 1)
      {
        $num = array_pop($sp);
        $numbers = explode('/', $num);
        if (is_numeric($numbers[0]))
        {
          $partner['cisloPopisne'] = $numbers[0];
          if (isset($numbers[1]))
            $partner['cisloOrientacni'] = $numbers[1];
          $partner['ulice'] = implode(' ', $sp);
        }
        else
          $partner['ulice'] = $item['street'];
      }
      else
        $partner['ulice'] = $item['street'];
    }

    if (isset($item['isCity']))
    {
      $partner['isCity'] = 1;
      $partner['obec'] = $this->cityById($partner['iczuj']);

      $item['city'] = $partner['obec'];
      $item['pn'] = 'Občané obce '.$partner['obec'];
      $partner['name'] = 'Občané obce '.$partner['obec'];
    }

    $this->partners[$partnerId] = $partner;

    return $partnerId;
  }

  protected function cityById($cityId)
  {
    $nc = $this->db()->query('SELECT * FROM e10_world_admUnits WHERE admUnitId = %i', intval($cityId), ' AND [level] = %i', 11)->fetch();
    if ($nc)
    {
      return $nc['fullName'];
    }

    return '';
  }

  public function createContent_Report_Load_WasteOps($dir, &$data)
  {
    $q = [];
    array_push ($q, 'SELECT [rows].wasteCodeNomenc, [rows].[dir], [rows].wasteHandlingCode, ');
    array_push ($q, ' SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
    array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [rows].rowSource = %i', 1);
    array_push ($q, ' AND [rows].dir = %i', $dir);
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);

    if ($this->oneWasteCode !== '')
      array_push ($q, ' AND nomencItems.itemId = %s', $this->oneWasteCode);

    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);

    array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].[dir], [rows].wasteHandlingCode');
    array_push ($q, ' ORDER BY [rows].wasteCodeNomenc');

    $cnt = 0;
		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
      $gid = 'G'.$r['itemId'];

      $wcc = $r['itemId'];
      $hcc = $r['wasteHandlingCode'];
      $hcCfg = $this->wasteHandlingCodes[$hcc] ?? NULL;
      $order = $r['itemId'].'_'.'A'.'_'.($hcCfg['sortOrder'] ?? 999).'_';

      $item = [
        'wasteCode' => $r['itemId'],
        'wasteName' => $r['fullName'],
        'hc' => $r['wasteHandlingCode'],
        'woDir' => $dir,
        'order' => $order,
        'rs' => 1,
      ];

      if (!isset($this->hcStates[$wcc]))
      {
        $this->hcStates[$wcc] = [
          'C00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
          'A00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],
          'B00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
          //'BN30' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
          'A10' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],

          //'XN3' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
        ];
      }

      if ($r['dir'] == WasteReturnEngine::rowDirIn)
      {
        if ($this->showUnits === 1)
          $item['quantityIn'] = round($r['quantityKG'] / 1000, 6);
        else
          $item['quantityIn'] = $r['quantityKG'];

        if (isset($hcCfg['initStateHC']))
          $this->hcStates[$wcc][$hcCfg['initStateHC']]['in'] += $item['quantityIn'];
        else
          $this->hcStates[$wcc][$hcc]['in'] += $item['quantityIn'];
      }
      elseif ($r['dir'] == WasteReturnEngine::rowDirOut)
      {
        if ($this->showUnits === 1)
          $item['quantityOut'] = round($r['quantityKG'] / 1000, 6);
        else
          $item['quantityOut'] = $r['quantityKG'];

        if (isset($hcCfg['initStateHC']))
          $this->hcStates[$wcc][$hcCfg['initStateHC']]['out'] += $item['quantityOut'];
      }

      if (!isset($data[$gid]))
      {
        $data[$gid] = [
          'wasteCode' => $r['itemId'],
          'wasteCodeNomenc' => $r['wasteCodeNomenc'],
          'wasteName' => $r['fullName'],
          'rows' => [],
        ];
      }

      $rowAdded = FALSE;
      foreach ($data[$gid]['rows'] as &$existingRow)
      {
        if ($existingRow['hc'] === $item['hc']  && isset($existingRow['woDir']) && $existingRow['woDir'] === $item['woDir'])
        {
          if ($dir == WasteReturnEngine::rowDirIn)
            $existingRow['quantityIn'] += $item['quantityIn'];
          else
            $existingRow['quantityOut'] += $item['quantityOut'];
          $rowAdded = TRUE;
          break;
        }
      }

      if (!$rowAdded)
        $data[$gid]['rows'][] = $item;

      $cnt++;
		}
  }

  public function createContent_Report_Load_WasteMoves($dir, &$data)
  {
    $q = [];
    array_push ($q, 'SELECT [rows].wasteCodeNomenc, [rows].[dir], [rows].wasteHandlingCode, ');
    array_push ($q, ' SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
    array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [rows].rowSource = %i', 0);
    array_push ($q, ' AND [rows].dir = %i', $dir);
    array_push ($q, ' AND [rows].personType = %i', 0);
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);

    if ($this->oneWasteCode !== '')
      array_push ($q, ' AND nomencItems.itemId = %s', $this->oneWasteCode);

    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);

    array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].[dir], [rows].wasteHandlingCode');
    array_push ($q, ' ORDER BY [rows].wasteCodeNomenc');

    $cnt = 0;
		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
      $gid = 'G'.$r['itemId'];

      $wcc = $r['itemId'];
      $hcc = $r['wasteHandlingCode'];
      $hcCfg = $this->wasteHandlingCodes[$hcc] ?? NULL;
      $order = $r['itemId'].'_'.'A'.'_'.($hcCfg['sortOrder'] ?? 999).'_';

      $item = [
        'wasteCode' => $r['itemId'],
        'wasteCodeNomenc' => $r['wasteCodeNomenc'],
        'wasteName' => $r['fullName'],
        'hc' => $r['wasteHandlingCode'],
        'order' => $order,
        'woDir' => $dir,
        'rs' => 1,
      ];

      if ($r['dir'] == WasteReturnEngine::rowDirIn)
      {
        if ($this->showUnits === 1)
          $item['quantityIn'] = round($r['quantityKG'] / 1000, 6);
        else
          $item['quantityIn'] = $r['quantityKG'];

        if (!isset($this->hcStates[$wcc]))
        {
          $this->hcStates[$wcc] = [
            'C00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
            'A00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],
            'B00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
            //'BN30' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
            'A10' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],

            //'XN3' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
          ];
        }

        $this->hcStates[$wcc][$hcc]['in'] += $item['quantityIn'];
      }
      elseif ($r['dir'] == WasteReturnEngine::rowDirOut)
      {
        if ($this->showUnits === 1)
          $item['quantityOut'] = round($r['quantityKG'] / 1000, 6);
        else
        $item['quantityOut'] = $r['quantityKG'];
      }

      if (!isset($data[$gid]))
      {
        $data[$gid] = [
          'wasteCode' => $r['itemId'],
          'wasteCodeNomenc' => $r['wasteCodeNomenc'],
          'wasteName' => $r['fullName'],
          'rows' => [],
        ];
      }

      if ($dir == WasteReturnEngine::rowDirIn)
      {
        $rowAdded = FALSE;
        foreach ($data[$gid]['rows'] as &$existingRow)
        {
          if ($existingRow['hc'] === $item['hc']  && isset($existingRow['woDir']) && $existingRow['woDir'] === $item['woDir'])
          {
            if ($dir == WasteReturnEngine::rowDirIn)
              $existingRow['quantityIn'] += $item['quantityIn'];
            else
              $existingRow['quantityOut'] += $item['quantityOut'];
            $rowAdded = TRUE;
            break;
          }
        }

        if (!$rowAdded)
          $data[$gid]['rows'][] = $item;
      }
      if ($dir == WasteReturnEngine::rowDirOut)
      {
        if ($hcc[0] === 'X')
        {
          $totalToOut = $item['quantityOut'];
          $restOut = $item['quantityOut'];

          foreach ($this->hcStates[$wcc] as $stateHC => &$states)
          {
            $available = $states['in'] - $states['out'];
            if ($available <= 0.0)
              continue;
            $thisOut = $totalToOut;
            if ($available <= $thisOut)
              $thisOut = $available;

            $states['out'] += $thisOut;
            $itemOut = $item;
            $itemOut['quantityOut'] = $thisOut;
            $itemOut['hc'] = $stateHC['0'].substr($hcc, 1);

            $totalToOut -= $thisOut;

            $data[$gid]['rows'][] = $itemOut;

            if ($totalToOut <= 0.0)
              break;
          }
        }
        else
        {
          $data[$gid]['rows'][] = $item;
        }
      }

      $cnt++;
		}
  }

  public function createContent_Report_Load_WasteSums(&$data)
  {
    $sumData = [];

    foreach ($data as $gid => $groupRows)
    {
      if (!isset($sumData[$gid]))
      {
        $sumData[$gid] = [
          'wasteCode' => $groupRows['wasteCode'],
          'wasteCodeNomenc' => $groupRows['wasteCodeNomenc'],
          'wasteName' => $groupRows['wasteName'],
          'rows' => [],
        ];
      }

      $sum ['wc'] = ['in' => 0.0, 'out' => 0.0];
      $rows = \e10\sortByOneKey($groupRows['rows'], 'order');
      foreach ($rows as $row)
      {
        $hc = $row['hc'];
        $hcCfg = $this->wasteHandlingCodes[$hc] ?? NULL;


        if (!isset($sumData[$gid]['rows'][$hc]))
        {
          $sumData[$gid]['rows'][$hc] = [
            'quantityIn' => 0.0,
            'quantityOut' => 0.0,
            'order' => '',
          ];
        }

        if (isset($row['quantityIn']))
        {
          $sumData[$gid]['rows'][$hc]['quantityIn'] += $row['quantityIn'];
          $sumData[$gid]['rows'][$hc]['order'] = '0_'.($hcCfg['sortOrder'] ?? '999').'_'.$hc;
        }
        if (isset($row['quantityOut']))
        {
          $sumData[$gid]['rows'][$hc]['quantityOut'] += $row['quantityOut'];
          $sumData[$gid]['rows'][$hc]['order'] = '0_'.($hcCfg['sortOrder'] ?? '999').'_'.$hc;
        }
      }
    }
    return $sumData;
  }

  public function createContent_Citizens()
  {
    $mde = new \e10doc\waster\libs\MunicipalityData($this->app());
    $mde->periodBegin = $this->periodBegin;
    $mde->periodEnd = $this->periodEnd;
    $mde->loadFromDb();
    $this->addContent (['type' => 'table', 'header' => $mde->headerCities, 'table' => $mde->dataCities, 'main' => TRUE]);
	  $this->setInfo('title', 'Odběr odpadů od občanů za obce');
  }

  protected function loadMunicipalityData()
  {
    /*
    if ($this->wasteReturnRecData['municipalityData'] ?? 0)
    {
      $this->loadMunicipalityData_File();
      return;
    }
    */
    $mde = new \e10doc\waster\libs\MunicipalityData($this->app());
    $mde->periodBegin = $this->periodBegin;
    $mde->periodEnd = $this->periodEnd;
    if ($this->oneWasteCode !== '')
      $mde->oneWasteCode = $this->oneWasteCode;
    $mde->loadFromDb();
    $mde->createMunicipalityData();
    $this->municipalityData = $mde->municipalityData;
  }

  public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icontxt' => '∑', 'title' => 'Sumárně'];
    $d[] = ['id' => 'report', 'icon' => 'system/iconFile', 'title' => 'Hlášení'];
    $d[] = ['id' => 'report2', 'icon' => 'system/iconFile', 'title' => 'Sumárně'];
    $d[] = ['id' => 'citizensCities', 'icon' => 'system/iconMapMarker', 'title' => 'Občané'];
    $d[] = ['id' => 'partners', 'icon' => 'system/iconUser', 'title' => 'Partneři'];

		return $d;
	}

  protected function personOid($personNdx)
  {
		$q = [];
    array_push ($q, 'SELECT * FROM [e10_base_properties] AS props');
		array_push ($q, ' WHERE [recid] = %i', $personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'oid');

    $rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$oid = trim($r['valueString']);
      return $oid;
		}

    return '';
  }

  protected function personICOB($personNdx)
  {
		$q = [];
    array_push ($q, 'SELECT * FROM [e10_base_properties] AS props');
		array_push ($q, ' WHERE [recid] = %i', $personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'cz_icob');

    $rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$id = trim($r['valueString']);
      return $id;
		}

    return '';
  }

  protected function personMainAddress($personNdx)
  {
    $q = [];
    array_push($q, 'SELECT addrs.*');
    array_push($q, ' FROM [e10_persons_personsContacts] AS [addrs]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [person] = %i', $personNdx);
    array_push($q, ' AND flagAddress = %i', 1);
    array_push($q, ' AND flagMainAddress = %i', 1);
    array_push($q, ' AND docState = %i', 4000);
    array_push($q, ' LIMIT 1');

    $address = $this->db()->query($q)->fetch();
    if ($address)
      return $address->toArray();

    return NULL;
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

  protected function wasteReturnsEnum()
  {
    $enum = [];

    $q = [];
    array_push($q, 'SELECT * FROM [e10doc_waster_wasteReturns]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [docState] = %i', 4000);
    array_push($q, ' ORDER BY [dateFrom] DESC');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $enum[$r['ndx']] = $r['title'];
    }

    return $enum;
  }
}
