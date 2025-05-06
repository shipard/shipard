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
  var $calendarYear = 0;
  var $persons = [];
  var $sendStatus = '';
  var $showUnits = -1;
  var $codeKindNdx = 0;
  var $useZipCode = 0;
  var $limitDistance = 0;
  var $limitKG = 0;
  var $officeLat = 0.0;
  var $officeLon = 0.0;

  var $municipalityData = [];

  var $thisCountryNdx = 60;

  var $partners = [];
  var $wastes = [];

  var $hcStates = [];

	public function init ()
	{
    $today = Utils::today();
    $defaultYear = 'Y'.(intval($today->format('Y')) - 1);
    $this->addParam ('calendarMonth', 'calendarPeriod', ['flags' => ['quarters', 'halfs', 'years'], 'defaultValue' => $defaultYear]);

    $ckEnum = $this->codesKindEnum();
    $this->addParam('switch', 'codeKind', ['title' => 'Druh', 'switch' => $ckEnum, 'radioBtn' => 1, '__defaultValue' => 'all']);

    if ($this->subReportId === 'companiesIn')
    {
      $this->addParam('switch', 'sendStatus', ['title' => 'Stav', 'switch' => ['all' => 'Vše', 'toSend' => 'Neodeslané', 'sent' => 'Odeslané'], 'radioBtn' => 1, 'defaultValue' => 'all']);
    }

    if ($this->subReportId === 'report')
      $this->addParam('switch', 'showUnits', ['title' => 'Jednotka', 'switch' => ['1' => 'Tuny', '0' => 'kg'], 'radioBtn' => 1, 'defaultValue' => '1']);


    if ($this->subReportId === 'citizensCities2')
    {
      $this->addParam('switch', 'useZipCode', ['title' => 'PSČ', 'switch' => ['0' => 'Ne', '1' => 'Ano'], 'radioBtn' => 1, 'defaultValue' => '0']);
      $this->addParam('switch', 'limitDistance', ['title' => 'Omezit vzdálenost', 'switch' => ['0' => 'Ne', '10' => '10 km', '15' => '15 km', '20' => '20 km', '30' => '30 km', '40' => '40 km', '50' => '50 km', '60' => '60 km', '70' => '70 km', '80' => '80 km', '90' => '90 km', '100' => '100 km'], 'defaultValue' => '0']);
      $this->addParam('switch', 'limitKG', ['title' => 'Limit', 'switch' => ['0' => 'Ne', '100' => '100 kg', '250' => '250 kg', '500' => '500 kg', '1000' => '1 tuna', '5000' => '5 tun'], 'defaultValue' => '0']);
    }

		parent::init();

    if ($this->sendStatus === '')
      $this->sendStatus = $this->reportParams ['sendStatus']['value'] ?? 'all';

    $this->showUnits = intval($this->reportParams ['showUnits']['value'] ?? '1');

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
      $this->setInfo('param', 'Období', $this->reportParams ['calendarPeriod']['activeTitle']);
    }
    else
    {
      $this->periodBegin = Utils::createDateTime($this->periodBegin);
      $this->periodEnd = Utils::createDateTime($this->periodEnd);
    }
  }

  function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Sum (); break;
			case 'companiesIn': $this->createContent_CompaniesIn (); break;
			case 'companiesOut': $this->createContent_CompaniesOut (); break;
			case 'citizensSum': $this->createContent_CitizensSum (); break;
			case 'citizensCities': $this->createContent_CitizensCities (); break;
      case 'citizensCities2': $this->createContent_CitizensCities2 (); break;
			case 'report': $this->createContent_Report (); break;
			case 'report2': $this->createContent_Report2 (); break;
			case 'partners': $this->createContent_Partners (); break;
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

  public function createContent_CompaniesIn()
  {
    $this->createContent_Companies(WasteReturnEngine::rowDirIn);
    $this->setInfo('title', 'Odběr odpadů od firem');
  }

  public function createContent_CompaniesOut()
  {
    $this->createContent_Companies(WasteReturnEngine::rowDirOut);
    $this->setInfo('title', 'Prodej odpadů');
  }

  public function createContent_Companies($dir)
  {
    if ($this->calendarYear)
    {
      if ($dir == WasteReturnEngine::rowDirIn)
        $linkId = 'waste-suppliers-'.$this->calendarYear.'-'.$this->codeKindNdx;
      else
        $linkId = 'waste-cust-'.$this->calendarYear.'-'.$this->codeKindNdx;
    }
    else
    {
      if ($dir == WasteReturnEngine::rowDirIn)
        $linkId = 'waste-suppliers-'.$this->periodBegin->format('Ymd').'_'.$this->periodEnd->format('Ymd').'-'.$this->codeKindNdx;
      else
        $linkId = 'waste-cust-'.$this->periodBegin->format('Ymd').'_'.$this->periodEnd->format('Ymd').'-'.$this->codeKindNdx;
    }

		/** @var \wkf\core\TableIssues */
		$tableIssues = $this->app()->table ('wkf.core.issues');
		$demandForPaySectionNdx = $tableIssues->defaultSection (121);
		$demandForPaySectionSecNdx = $tableIssues->defaultSection (20);

    $q = [];

    array_push ($q, 'SELECT [rows].person, [rows].personOffice, [rows].wasteCodeNomenc, [rows].[dir], [rows].[addressMode], [rows].[nomencCity],');
    array_push ($q, ' SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId,');
    array_push ($q, ' persons.fullName AS personFullName,');
    array_push ($q, ' addrs.adrCity, addrs.adrStreet, addrs.id1, addrs.id2');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_personsContacts] AS addrs ON [rows].personOffice = addrs.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON [rows].person = persons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [rows].personType = %i', 2);
    array_push ($q, ' AND [rows].[dir] = %i', $dir);
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);

    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);
		array_push ($q, ' GROUP BY [rows].person, [rows].addressMode, [rows].personOffice, [rows].nomencCity, [rows].[dir], wasteCodeNomenc');

    if ($this->sendStatus !== 'all')
    {
      if ($this->sendStatus === 'toSend')
        array_push($q, ' HAVING NOT EXISTS ');
      else
        array_push($q, ' HAVING EXISTS ');

      array_push($q, '(SELECT ndx FROM [wkf_core_issues] ');
      array_push($q, ' WHERE tableNdx = %i', 1000);
      if ($demandForPaySectionNdx && $demandForPaySectionSecNdx)
        array_push($q, ' AND section IN %in', [$demandForPaySectionNdx, $demandForPaySectionSecNdx]);
      else
        array_push($q, ' AND section = %i', $demandForPaySectionNdx);

      array_push($q, ' AND linkId = %s', $linkId);
      array_push($q, ' AND wkf_core_issues.recNdx = [rows].person');
      array_push($q, ' AND docStateMain = %i', 2);
      array_push($q, ')');
    }

    array_push ($q, ' ORDER BY persons.fullName, addrs.id1, wasteCodeNomenc');

    $lastPerson = -1;
		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
      if (!in_array($r['person'], $this->persons))
        $this->persons[] = $r['person'];

      if ($r['person'] != $lastPerson)
      {
        $header = [
          'wasteCode' => [
            ['text' => $r['personFullName'], 'docAction' => 'edit', 'pk' => $r['person'], 'table' => 'e10.persons.persons']
          ]  ,
          '_options' => [
            'colSpan' => ['wasteCode' => 4],
            'class' => 'subheader',
          ]
        ];

        $header['wasteCode'][] = [
          'text' => 'Načíst provozovny', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'user/wifi',
          'class' => 'pull-right',
          'btnClass' => 'btn btn-xs btn-primary pull-right',
          'data-class' => 'e10.persons.libs.register.AddOfficesWizard',
          'table' => 'e10.persons.persons',
          'data-addparams' => 'personNdx='.$r['person'],
          //'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid,
        ];

        $header['wasteCode'][] = [
          'text' => 'Nastavit provozovnu', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/personCompany',
          'class' => 'pull-right',
          'btnClass' => 'btn btn-xs btn-success pull-right',
          'data-class' => 'e10pro.reports.waste_cz.libs.SetOfficeWizard',
          'table' => 'e10.persons.persons',
          'data-addparams' => 'personNdx='.$r['person'].'&dir='.$r['dir'].'&periodBegin='.$this->periodBegin->format('Y-m-d').
                              '&periodEnd='.$this->periodEnd->format('Y-m-d').
                              '&calendarYear='.$this->calendarYear,
          //'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid,
        ];


        // -- print button
        if ($dir === WasteReturnEngine::rowDirIn)
        {
          $btn = ['type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'system/actionPrint', 'text' => 'Přehled',
            'data-report' => 'e10pro.reports.waste_cz.ReportWasteOnePerson',
            'data-table' => 'e10.persons.persons', 'data-pk' => $r['person'],
            'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
            'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
            'data-param-calendar-year' => strval($this->calendarYear),
            'data-param-code-kind' => strval($this->codeKindNdx),
            'actionClass' => 'btn-xs', 'class' => 'pull-right'];
          $btn['subButtons'] = [];
          $btn['subButtons'][] = [
            'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconEmail', 'title' => 'Odeslat emailem', 'btnClass' => 'btn-default btn-xs',
            'data-table' => 'e10.persons.persons', 'data-pk' => $r['person'],
            'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
            'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
            'data-param-calendar-year' => strval($this->calendarYear),
            'data-param-code-kind' => strval($this->codeKindNdx),
            'data-class' => 'Shipard.Report.SendFormReportWizard',
            'data-addparams' => 'reportClass=' . 'e10pro.reports.waste_cz.ReportWasteOnePerson' . '&documentTable=' . 'e10.persons.persons'
          ];
          $header['wasteCode'][] = $btn;
        }
        elseif ($dir === WasteReturnEngine::rowDirOut)
        {
          $btn = ['type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'system/actionPrint', 'text' => 'Přehled',
            'data-report' => 'e10pro.reports.waste_cz.ReportWasteOnePersonOut',
            'data-table' => 'e10.persons.persons', 'data-pk' => $r['person'],
            'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
            'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
            'data-param-calendar-year' => strval($this->calendarYear),
            'data-param-code-kind' => strval($this->codeKindNdx),
            'actionClass' => 'btn-xs', 'class' => 'pull-right'];
          $btn['subButtons'] = [];
          $btn['subButtons'][] = [
            'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconEmail', 'title' => 'Odeslat emailem', 'btnClass' => 'btn-default btn-xs',
            'data-table' => 'e10.persons.persons', 'data-pk' => $r['person'],
            'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
            'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
            'data-param-calendar-year' => strval($this->calendarYear),
            'data-class' => 'Shipard.Report.SendFormReportWizard',
            'data-param-code-kind' => strval($this->codeKindNdx),
            'data-addparams' => 'reportClass=' . 'e10pro.reports.waste_cz.ReportWasteOnePersonOut' . '&documentTable=' . 'e10.persons.persons'
          ];
          $header['wasteCode'][] = $btn;
        }

        $header['_options']['beforeSeparator'] = 'separator';

        $data['HDR_'.$r['person']] = $header;
      }

      $wcId = 'W-'.$r['person'].'-'.$r['wasteCodeNomenc'].'-'.$r['addressMode'].'-'.$r['personOffice'].'-'.$r['nomencCity'];

      if (!isset($data[$wcId]))
      {
        $data[$wcId] = [
          'wasteCode' => $r['itemId'],
          'wasteName' => $r['fullName'],
        ];
      }

      if ($r['dir'] == WasteReturnEngine::rowDirIn)
        $data[$wcId]['quantityIn'] = $r['quantityKG'];
      elseif ($r['dir'] == WasteReturnEngine::rowDirOut)
        $data[$wcId]['quantityOut'] = $r['quantityKG'];

      if ($r['addressMode'] === 0)
      { // office
        if (($r['id1'] && $r['id1'] !== '') || ($r['id2'] && $r['id2'] !== ''))
        {
          $data[$wcId]['id1'] = [];
          if ($r['id2'] && $r['id2'] !== '')
          {
            $data[$wcId]['id1'][] = [
              ['text' => 'IČZ: ', 'class' => ''],
              ['text' => $r['id2'], 'class' => ''],
            ];
            $data[$wcId]['icz'] = $r['id2'];
          }
          if ($r['id1'] && $r['id1'] !== '')
          {
            $data[$wcId]['id1'][] = [
              ['text' => 'IČP: ', 'class' => ''],
              [
                'text' => $r['id1'], 'docAction' => 'edit', 'pk' => $r['personOffice'],
                'table' => 'e10.persons.personsContacts', 'class' => '',
                'suffix' => $r['adrStreet'].', '.$r['adrCity'],
              ],
            ];
            $data[$wcId]['icp'] = $r['id1'];
          }
        }
      }
      else
      { // city
        $nomencCityRecData = $this->app()->loadItem($r['nomencCity'], 'e10.base.nomencItems');
        $data[$wcId]['id1'] = [
          ['text' => 'ORP: '.substr($nomencCityRecData['itemId'] ?? '--', 2), 'class' => ''],
        ];
        $data[$wcId]['id1'][0]['suffix'] = $nomencCityRecData['fullName'] ?? '--';
        $data[$wcId]['orp'] = $nomencCityRecData['itemId'] ?? '0';
      }

      $lastPerson = $r['person'];
		}

    $this->loadSendedReports ($data, $dir);

		$h = [
      'wasteCode' => 'Kód odpadu',
      'wasteName' => 'Text',
      'id1' => 'Místo',
      'quantityIn' => ' Příjem [kg]',
      'quantityOut' => ' Výdej [kg]',
    ];
    if ($dir === WasteReturnEngine::rowDirIn)
      unset($h['quantityOut']);
    elseif ($dir === WasteReturnEngine::rowDirOut)
      unset($h['quantityIn']);

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);
  }

  public function createContent_Report()
  {
    $handlingCodes = $this->app()->cfgItem('e10doc.waster.handlingCodes', []);

    $data = [];

    $this->loadMunicipalityData();

    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirIn, $data); // in ops
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirIn, $data); // companies IN
    $this->createContent_Report_Load(1, WasteReturnEngine::rowDirIn, $data); // humans
    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirOut, $data); // out ops
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirOut, $data); // companies OUT

    $sum = [
      'wc' => ['in' => 0.0, 'out' => 0.0],
      'total' => ['in' => 0.0, 'out' => 0.0],
    ];

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

      $sum ['wc'] = ['in' => 0.0, 'out' => 0.0];
      $rows = \e10\sortByOneKey($groupRows['rows'], 'order');
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
          $hcCfg = $handlingCodes[$row['hc']] ?? NULL;
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

        $t[] = $row;
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
        'params' => ['tableClass' => 'e10-print-small default', 'precision' => ($this->showUnits === 1) ? 6 : 2]
      ]);

    $this->setInfo('title', 'Roční hlášení o produkci a nakládání s odpady');
    $this->paperOrientation = 'landscape';

    $this->wastes = $data;
  }

  public function createContent_Report2()
  {
    $handlingCodes = $this->app()->cfgItem('e10doc.waster.handlingCodes', []);

    $data = [];

    $this->loadMunicipalityData();

    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirIn, $data); // in ops
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirIn, $data); // companies IN
    $this->createContent_Report_Load(1, WasteReturnEngine::rowDirIn, $data); // humans
    $this->createContent_Report_Load_WasteOps(WasteReturnEngine::rowDirOut, $data); // out ops
    $this->createContent_Report_Load(2, WasteReturnEngine::rowDirOut, $data); // companies OUT


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

      $rows = \e10\sortByOneKey($groupRows['rows'], 'order', TRUE);
      foreach (/*$groupRows['rows']*/$rows as $hc => $hcRow)
      {
        $sumRow = [
          'hc' => $hc,
          // 'qs' => $hccData['in'] - $hccData['out'],
        ];

        if (isset($handlingCodes[$hc]))
          $sumRow['hc'] = $handlingCodes[$hc]['sn'];

        if ($hcRow['quantityIn'] ?? 0)
          $sumRow['quantityIn'] = $hcRow['quantityIn'];
        if ($hcRow['quantityOut'] ?? 0)
          $sumRow['quantityOut'] = $hcRow['quantityOut'];



        $wcSum['quantityIn'] += $sumRow['quantityIn'] ?? 0.0;
        $wcSum['quantityOut'] += $sumRow['quantityOut'] ?? 0.0;

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

      if (abs(round($sumRow['quantityIn'] - $sumRow['quantityOut'], 6)) >= 0.000001)
      {
        $wcc = $groupRows['wasteCode'];
        foreach ($this->hcStates[$wcc] as $hcc => $hccData)
        {
          if (abs(round($hccData['in'] - $hccData['out'], 6)) < 0.000001)
            continue;

          $item = [
            'hc' => $hcc.': '.round($sumRow['quantityIn'] - $sumRow['quantityOut'], 6).' / '.json_encode($hccData),
            //'quantityIn' => $hccData['in'],
            //'quantityOut' => $hccData['out'],
          ];

          $t[] = $item;
        }
      }


    }

		$h = [
      //'#' => '#',
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
        'params' => ['tableClass' => 'e10-print-small default']
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

    if ($personType === 2) // companies
    {
      array_push ($q, 'SELECT [rows].person, [rows].personOffice, [rows].wasteCodeNomenc, [rows].wasteHandlingCode, [rows].[dir], [rows].[addressMode], [rows].[nomencCity],');
      array_push ($q, ' SUM([rows].quantityKG) as quantityKG,');
      array_push ($q, ' nomencItems.fullName, nomencItems.itemId,');
      array_push ($q, ' persons.fullName AS personFullName,');
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

    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);

    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);

    if ($personType === 2)
    { // companies
      //[rows].quantityKG
      array_push ($q, ' AND [rows].[quantityKG] != 0');

		  array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].person, [rows].addressMode, [rows].personOffice, [rows].nomencCity, [rows].[dir], [rows].wasteHandlingCode');
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

      $personOid = '';
      $personICOB = '';
      $pn = '';
      $order = $r['itemId'].'_'.$r['dir'].'_'.$r['wasteHandlingCode'];

      if ($personType === 2)
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
          $nomencCityRecData = $this->app()->loadItem($r['nomencCity'], 'e10.base.nomencItems');
          $orp = substr($nomencCityRecData['itemId'] ?? '', 2);
          $item['id1'] = [
            ['text' => 'ORP: '.$orp, 'class' => ''],
          ];
          $item['id1'][0]['suffix'] = $nomencCityRecData['fullName'] ?? '!!!!';
          $item['id_orp'] = $orp;
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
          'wasteName' => $r['fullName'],
          'rows' => [],
        ];
      }

      $wcc = $r['itemId'];
      $hcc = $item['hc'];
      if ($wasteDir == WasteReturnEngine::rowDirIn)
      {
        if (!isset($this->hcStates[$wcc]))
        {
          $this->hcStates[$wcc] = [
            'C00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
            'A00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],
            'B00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
            'BN30' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
          ];
        }

        $this->hcStates[$wcc][$hcc]['in'] += $item['quantityIn'];
        $data[$gid]['rows'][] = $item;
      }
      elseif ($wasteDir == WasteReturnEngine::rowDirOut)
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

      $order = $r['wasteCode'].'_'.'1'.'_'.$r['hc'];

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

      if (!isset($data[$gid]))
      {
        $data[$gid] = [
          'wasteCode' => $r['wasteCode'],
          'wasteName' => $r['wasteCode'],
          'rows' => [],
        ];
      }

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
      $partner['iczuj'] = strval($this->natCityId($item['city'] ?? ''));
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

  protected function natCityId($city)
  {
    $natCityId = 585068;

    $nc = $this->db()->query('SELECT * FROM e10_base_nomencItems WHERE shortName = %s', $city, ' AND [level] = %i', 2, ' AND id LIKE %s', 'cz-orp%')->fetch();
    if ($nc)
    {
      $natCityId = intval(substr($nc['itemId'], 2));
      if ($natCityId)
        return $natCityId;
    }
    //error_log('SRCH-ICZUJ: '.json_encode($city));
    return $natCityId;
  }

  protected function cityById($cityId)
  {
    $nc = $this->db()->query('SELECT * FROM e10_base_nomencItems WHERE itemId = %s', 'CZ'.$cityId)->fetch();
    if ($nc)
    {
      return $nc['shortName'];
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

      if ($dir == WasteReturnEngine::rowDirIn)
        $order = $r['itemId'].'_'.$r['dir'].'_'.'00'.'000000000';
      else
        $order = $r['itemId'].'_'.$r['dir'].'_'.'ZZ'.'ZZZZZZZZZ';

      $item = [
        'wasteCode' => $r['itemId'],
        'wasteName' => $r['fullName'],
        'hc' => $r['wasteHandlingCode'],
        'order' => $order,
        'rs' => 1,
      ];

      if ($r['dir'] == WasteReturnEngine::rowDirIn)
      {
        if ($this->showUnits === 1)
          $item['quantityIn'] = round($r['quantityKG'] / 1000, 6);
        else
          $item['quantityIn'] = $r['quantityKG'];

        $wcc = $r['itemId'];
        $hcc = $item['hc'];
        if (!isset($this->hcStates[$wcc]))
        {
          $this->hcStates[$wcc] = [
            'C00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
            'A00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],
            'B00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
            'BN30' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
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
          'wasteName' => $r['fullName'],
          'rows' => [],
        ];
      }

      $data[$gid]['rows'][] = $item;

      $cnt++;
		}
  }

  public function createContent_Report_Load_WasteSums(&$data)
  {
    $sumData = [];
    $handlingCodes = $this->app()->cfgItem('e10doc.waster.handlingCodes', []);


    foreach ($data as $gid => $groupRows)
    {
      if (!isset($sumData[$gid]))
      {
        $sumData[$gid] = [
          'wasteCode' => $groupRows['wasteCode'],
          'wasteName' => $groupRows['wasteName'],
          'rows' => [],
        ];
      }

      $sum ['wc'] = ['in' => 0.0, 'out' => 0.0];
      $rows = \e10\sortByOneKey($groupRows['rows'], 'order');
      foreach ($rows as $row)
      {
        $hc = $row['hc'];
        $hcCfg = $handlingCodes[$hc] ?? NULL;


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
          $sumData[$gid]['rows'][$hc]['order'] = '1_'.($hcCfg['sortOrder'] ?? '999').'_'.$hc;
        }
      }
    }
    return $sumData;
  }

  public function createContent_CitizensSum()
  {
    $q = [];

    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);
		array_push ($q, ' AND [rows].personType = %i', 1);
    array_push ($q, ' AND [rows].[dir] = %i', 0);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);
		array_push ($q, ' GROUP BY wasteCodeNomenc');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
      $item = [
        'wasteCode' => $r['itemId'],
        'wasteName' => $r['fullName'],
        'quantity' => $r['quantityKG'],
      ];
			$data[] = $item;
		}

		$h = ['wasteCode' => ' Kód odpadu', 'wasteName' => 'Text', 'quantity' => ' Množství [kg]'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

		$this->setInfo('title', 'Odběr odpadů od občanů');
  }

  public function createContent_CitizensCities()
  {
    $q = [];

    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId,');
    array_push ($q, ' addrs.adrCity');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_personsContacts] AS addrs ON [rows].personOffice = addrs.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);
		array_push ($q, ' AND [rows].personType = %i', 1);
    array_push ($q, ' AND [rows].[dir] = %i', 0);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);
		array_push ($q, ' GROUP BY addrs.adrCity, wasteCodeNomenc');

    $lastCity = '______';
		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
      if ($r['adrCity'] !== $lastCity)
      {
        $header = [
          'wasteCode' => ($r['adrCity'] == '') ? '-- NEUVEDENO --' : $r['adrCity'],
          '_options' => [
            'colSpan' => ['wasteCode' => 3],
            'class' => 'subheader',
          ]
        ];

        $header['_options']['beforeSeparator'] = 'separator';

        $data[] = $header;
      }
      $item = [
        'wasteCode' => $r['itemId'],
        'wasteName' => $r['fullName'],
        'quantity' => $r['quantityKG'],
      ];
			$data[] = $item;

      $lastCity = $r['adrCity'];
		}

		$h = ['wasteCode' => 'Kód odpadu', 'wasteName' => 'Text', 'quantity' => ' Množství [kg]'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

		$this->setInfo('title', 'Odběr odpadů od občanů za obce');
  }

  public function createContent_CitizensCities2()
  {
    $this->useZipCode = intval($this->reportParams ['useZipCode']['value'] ?? '0');
    $this->limitDistance = intval($this->reportParams ['limitDistance']['value'] ?? '0');
    $this->limitKG = intval($this->reportParams ['limitKG']['value'] ?? '0');

    $q = [];
    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId,');
    array_push ($q, ' addrs.adrCity, addrs.adrZipCode, addrs.adrLocLat, addrs.adrLocLon, addrs.adrLocState, addrs.adrCountry,');
    array_push ($q, ' ownerOffices.adrLocLat AS ownerAdrLocLat, ownerOffices.adrLocLon AS ownerAdrLocLon');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_personsContacts] AS addrs ON [rows].personOffice = addrs.ndx');
    array_push ($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_personsContacts] AS ownerOffices ON [heads].ownerOffice = ownerOffices.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);
		array_push ($q, ' AND [rows].personType = %i', 1);
    array_push ($q, ' AND [rows].[dir] = %i', 0);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);

    if ($this->useZipCode)
		  array_push ($q, ' GROUP BY addrs.adrCity, addrs.adrZipCode, wasteCodeNomenc');
    else
      array_push ($q, ' GROUP BY addrs.adrCountry, addrs.adrCity, wasteCodeNomenc');

    array_push ($q, ' ORDER BY addrs.adrCountry, addrs.adrCity, wasteCodeNomenc');

		$rows = $this->app->db()->query ($q);
    $header = ['#' => '#', 'city' => 'Obec', 'zip' => 'PSČ', 'dist' => ' Vzdál. KM'];

		$data = [];
		forEach ($rows as $r)
		{
      $this->officeLat = $r['ownerAdrLocLat'];
      $this->officeLon = $r['ownerAdrLocLon'];

      if ($this->useZipCode)
        $cityId = $r['adrCountry'].'_'.$r['adrCity'].'_'.$r['adrZipCode'];
      else
        $cityId = $r['adrCountry'].'_'.$r['adrCity'];

      $cityName = $r['adrCity'];

      $distance = 0;
      if ($r['adrLocState'] === 1)
      {
        $distance = round($this->computeDistance($r['adrLocLat'], $r['adrLocLon'], $this->officeLat, $this->officeLon) / 1000, 1);
      }

      $country = World::country($this->app(), $r['adrCountry']);
      if ($this->thisCountryNdx !== $r['adrCountry'])
      {
        $cityId = '__COUNTRY__'.$r['adrCountry'];
        if ($country)
          $cityName = 'CELÝ STÁT: '.$country['t'];
        else
          $cityName = '=== NENÍ ZADÁN STÁT ===';

        $distance = 0;
      }
      else
      if ($this->limitDistance && $distance > $this->limitDistance)
      {
        if (!$this->limitKG || $r['quantityKG'] < $this->limitKG)
        {
          $cityId = '__OTHER__';
          $cityName = 'OSTATNÍ';
        }
      }

      if ($r['adrCity'] == '')
      {
        $cityId = '__OTHER__';
        $cityName = 'OSTATNÍ';
      }

      if (!isset($data[$cityId]))
        $data[$cityId] = ['city' => $cityName, 'zip' => $r['adrZipCode'], 'dist' => $distance];

      $wid = $r['itemId'];
      if (!isset($header[$wid]))
        $header[$wid] = '+'.$r['itemId'].': '.$r['fullName'];

      if (!isset($data[$cityId][$wid]))
        $data[$cityId][$wid] = $r['quantityKG'];
      else
        $data[$cityId][$wid] += $r['quantityKG'];
		}

    if (!$this->useZipCode)
      unset($header['zip']);

		$this->addContent (['type' => 'table', 'header' => $header, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Odběr odpadů od občanů za obce');
  }

  function loadSendedReports (&$data, $dir)
	{
    if ($this->calendarYear)
    {
      if ($dir == WasteReturnEngine::rowDirIn)
        $linkId = 'waste-suppliers-'.$this->calendarYear.'-'.$this->codeKindNdx;
      else
        $linkId = 'waste-cust-'.$this->calendarYear.'-'.$this->codeKindNdx;
    }
    else
    {
      if ($dir == WasteReturnEngine::rowDirIn)
        $linkId = 'waste-suppliers-'.$this->periodBegin->format('Ymd').'_'.$this->periodEnd->format('Ymd').'-'.$this->codeKindNdx;
      else
        $linkId = 'waste-cust-'.$this->periodBegin->format('Ymd').'_'.$this->periodEnd->format('Ymd').'-'.$this->codeKindNdx;
    }

		/** @var \wkf\core\TableIssues */
		$tableIssues = $this->app()->table ('wkf.core.issues');
		$demandForPaySectionNdx = $tableIssues->defaultSection (121);
		$demandForPaySectionSecNdx = $tableIssues->defaultSection (20);

		$q[] = 'SELECT * FROM [wkf_core_issues] ';
		array_push($q, ' WHERE tableNdx = %i', 1000);
		if ($demandForPaySectionNdx && $demandForPaySectionSecNdx)
			array_push($q, ' AND section IN %in', [$demandForPaySectionNdx, $demandForPaySectionSecNdx]);
		else
			array_push($q, ' AND section = %i', $demandForPaySectionNdx);

		array_push($q, ' AND linkId = %s', $linkId);
		array_push($q, ' AND recNdx IN %in', $this->persons);
    array_push($q, ' AND docStateMain = %i', 2);
		array_push($q, ' ORDER BY [dateCreate]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
					'icon' => 'system/iconPaperPlane', 'text' => utils::datef($r['dateCreate'], '%D%t'),
          'class' => 'pull-right',
          'type' => 'button',
          'title' => $r['subject'],
          'actionClass' => 'btn btn-info btn-xs',
					'docAction' => 'edit', 'table' => 'wkf.core.issues', 'pk' => $r['ndx']
			];
      $data['HDR_'.$r['recNdx']]['wasteCode'][] = $item;
		}
	}

  protected function loadMunicipalityData()
  {
    $sum20 = 0.0;
    $returnNdx = 1;
    $returnRecData = $this->app()->loadItem($returnNdx, 'e10doc.waster.wasteReturns');
    if (!$returnRecData)
      return;
    if (!isset($returnRecData['municipalityData']))
      return;

    $attRecData = $this->db()->query ('SELECT * FROM [e10_attachments_files] WHERE [ndx] = %i', $returnRecData['municipalityData'])->fetch();
    $fileName = 'att/'.$attRecData['path'] . $attRecData['filename'];

    $file = fopen($fileName, "r");

    $cnt = 0;
    while ($cols = fgetcsv($file, null, ','))
    {
      if ($cnt === 0)
      {
        $cnt = 1;
        continue;
      }

      if (($cols[2] ?? '') === '')
        continue;


      $iczuj = strval($this->natCityId($cols[7] ?? ''));

      $item = [
        'wasteCode' => $cols[2],
        'hc' => $cols[4],
        'quantity' => floatval($cols[5]),
        'cityName' => $cols[7],
        'iczuj' => $iczuj,
      ];

      $wcc = $item['wasteCode'];
      $hcc = $item['hc'];
      $order = $wcc.'_'.'0'.'_'.$hcc.'_'.$cnt;
      $item['order'] = $order;

      if (!isset($this->hcStates[$wcc]))
      {
        $this->hcStates[$wcc] = [
          'C00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'CN3'],
          'A00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'AN3'],
          'B00' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
          'BN30' => ['in' => 0.0, 'out' => 0.0, 'outHc' => 'BN3'],
        ];
      }

      $this->hcStates[$wcc][$hcc]['in'] += $item['quantity'];

      if ($wcc == '200140')
        $sum20 += $item['quantity'];

      //error_log("ITEM: ".json_encode($item));

      $this->municipalityData[] = $item;

      $cnt++;
    }

    error_log("SUM-20: ".$sum20);

    fclose($file);
  }

  public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icontxt' => '∑', 'title' => 'Sumárně'];
    $d[] = ['id' => 'companiesIn', 'icon' => 'system/personCompany', 'title' => 'Firmy Příjem'];
    $d[] = ['id' => 'companiesOut', 'icon' => 'system/iconDelivery', 'title' => 'Firmy Výdej'];
    $d[] = ['id' => 'citizensSum', 'icon' => 'system/personHuman', 'title' => 'Občané'];
    $d[] = ['id' => 'citizensCities', 'icon' => 'system/iconMapMarker', 'title' => 'Občané podle obcí'];
    $d[] = ['id' => 'citizensCities2', 'icon' => 'system/iconMapMarker', 'title' => 'Občané podle obcí 2'];
    $d[] = ['id' => 'report', 'icon' => 'system/iconFile', 'title' => 'Hlášení'];
    $d[] = ['id' => 'report2', 'icon' => 'system/iconFile', 'title' => 'Sumárně'];
    $d[] = ['id' => 'partners', 'icon' => 'system/iconUser', 'title' => 'Partneři'];

		return $d;
	}

	public function createToolbar ()
	{
		$buttons = parent::createToolbar();
    if ($this->subReportId === 'companiesIn')
    {
      $buttons[] = [
        'text' => 'Rozeslat hromadně emailem', 'icon' => 'system/iconEmail',
        'type' => 'action', 'action' => 'addwizard', 'data-class' => 'e10pro.reports.waste_cz.ReportWasteOnePersonWizard',
        'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
        'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
        'data-param-calendar-year' => strval($this->calendarYear),
        'data-param-code-kind' => strval($this->codeKindNdx),
        'data-param-send-status' => strval($this->sendStatus),
        'data-table' => 'e10.persons.persons', 'data-pk' => '0',
        'class' => 'btn-primary'
      ];
    }
		return $buttons;
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

  function computeDistance($lat1, $lng1, $lat2, $lng2, $radius = 6378137)
  {
    static $x = M_PI / 180;
    $lat1 *= $x; $lng1 *= $x;
    $lat2 *= $x; $lng2 *= $x;
    $distance = 2 * asin(sqrt(pow(sin(($lat1 - $lat2) / 2), 2) + cos($lat1) * cos($lat2) * pow(sin(($lng1 - $lng2) / 2), 2)));

    return $distance * $radius;
  }
}
