<?php

namespace e10pro\reports\waste_cz\libs;


use \Shipard\Utils\Utils;
use \Shipard\Utils\World;
use \e10pro\reports\waste_cz\libs\WasteReturnEngine;

/**
 * class ReportWasteCompanies
 */
class ReportWasteCompanies extends \e10doc\core\libs\reports\GlobalReport
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;
  var $calendarYear = 0;
  var $persons = [];
  var $sendStatus = '';
  var $showUnits = 0;
  var $codeKindNdx = 0;
  var $useZipCode = 0;
  var $limitDistance = 0;
  var $limitKG = 0;
  var $officeLat = 0.0;
  var $officeLon = 0.0;

  var $thisCountryNdx = 60;

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
      $this->addParam('switch', 'limitDistance', ['title' => 'Omezit vzdálenost', 'switch' => ['0' => 'Ne', '10' => '10 km', '20' => '20 km', '30' => '30 km', '40' => '40 km', '50' => '50 km', '60' => '60 km', '70' => '70 km', '80' => '80 km', '90' => '90 km', '100' => '100 km'], 'defaultValue' => '0']);
      $this->addParam('switch', 'limitKG', ['title' => 'Limit', 'switch' => ['0' => 'Ne', '100' => '100 kg', '250' => '250 kg', '500' => '500 kg', '1000' => '1 tuna', '5000' => '5 tun'], 'defaultValue' => '0']);
    }

		parent::init();

    if ($this->sendStatus === '')
      $this->sendStatus = $this->reportParams ['sendStatus']['value'] ?? 'all';

    $this->showUnits = intval($this->reportParams ['showUnits']['value'] ?? '0');

    if (!$this->codeKindNdx)
      $this->codeKindNdx = intval($this->reportParams ['codeKind']['value']);

    $cpBegin = $this->reportParams ['calendarPeriod']['values'][$this->reportParams ['calendarPeriod']['value']];
    if (isset($cpBegin['dateBegin']))
      $this->periodBegin = Utils::createDateTime($cpBegin['dateBegin']);
    elseif ($cpBegin['calendarYear'] !== 0)
      $this->periodBegin = Utils::createDateTime(substr($cpBegin['calendarYear'], 1).'-01-01');

    if (isset($cpBegin['dateEnd']))
      $this->periodEnd = Utils::createDateTime($cpBegin['dateEnd']);
    elseif ($cpBegin['calendarYear'] !== 0)
      $this->periodEnd = Utils::createDateTime(substr($cpBegin['calendarYear'], 1).'-12-31');

    $this->setInfo('icon', 'reportMonthlyReport');
    $this->setInfo('param', 'Období', $this->reportParams ['calendarPeriod']['activeTitle']);

    if (is_string($cpBegin['calendarYear']) && $cpBegin['calendarYear'][0] === 'Y')
      $this->calendarYear = intval(substr($cpBegin['calendarYear'], 1));
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
    if ($dir == WasteReturnEngine::rowDirIn)
      $linkId = 'waste-suppliers-'.$this->calendarYear.'-'.$this->codeKindNdx;
    else
      $linkId = 'waste-cust-'.$this->calendarYear.'-'.$this->codeKindNdx;

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
          'data-addparams' => 'personNdx='.$r['person'].'&dir='.$r['dir'],
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
    $data = [];
    $this->createContent_Report_Load(2, $data); // companies
    $this->createContent_Report_Load(1, $data); // humans

    $data = \e10\sortByOneKey($data, 'order');

    $t = [];
    $sum = [
      'wc' => ['in' => 0.0, 'out' => 0.0],
      'total' => ['in' => 0.0, 'out' => 0.0],
    ];
    $lastWasteCode = '___';
    foreach ($data as $row)
    {
      if ($row['wasteCode'] !== $lastWasteCode)
      {
        if ($lastWasteCode !== '___')
        {
          $sumRow = [
            'wasteCode' => 'CELKEM', 'quantityIn' => $sum['wc']['in'], 'quantityOut' => $sum['wc']['out'],
            '_options' => ['class' => 'subtotal',]
          ];
          $t[] = $sumRow;

          $sum['wc']['in'] = 0.0;
          $sum['wc']['out'] = 0.0;
        }

        $header = [
          'wasteCode' => $row['wasteCode'].': '.$row['wasteName'],
          '_options' => [
            'colSpan' => ['wasteCode' => 6],
            'class' => 'subheader',
          ]
        ];
        $header['_options']['beforeSeparator'] = 'separator';
        $t[] = $header;
      }

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


      $t [] = $row;
      $lastWasteCode = $row['wasteCode'];
    }
    $sumRow = [
      'wasteCode' => 'CELKEM', 'quantityIn' => $sum['wc']['in'], 'quantityOut' => $sum['wc']['out'],
      '_options' => ['class' => 'subtotal',]
    ];
    $t[] = $sumRow;

    $sumRow = [
      'wasteCode' => 'CELKEM', 'quantityIn' => $sum['total']['in'], 'quantityOut' => $sum['total']['out'],
      '_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator']
    ];
    $t[] = $sumRow;

		$h = [
      'wasteCode' => 'Kód odp.',
      'quantityIn' => ' Příjem '.(($this->showUnits === 1) ? '[t]' : '[kg]'),
      'quantityOut' => ' Výdej '.(($this->showUnits === 1) ? '[t]' : '[kg]'),
      'oid' => 'IČ',
      'pn' => 'Firma',
      'id1' => 'IČP',
      'id2' => 'IČZ',
      'id3' => 'IČOB',
      'street' => 'Ulice',
      'city' => 'Město',
      'zipCode' => 'PSČ',
    ];
		$this->addContent (
      [
        'type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE,
        'params' => ['tableClass' => 'e10-print-small default', 'precision' => ($this->showUnits === 1) ? 3 : 2]
      ]);

    $this->setInfo('title', 'Roční hlášení o produkci a nakládání s odpady');
    $this->paperOrientation = 'landscape';
  }

  public function createContent_Report_Load($personType, &$data)
  {
    $q = [];

    if ($personType === 2) // companies
    {
      array_push ($q, 'SELECT [rows].person, [rows].personOffice, [rows].wasteCodeNomenc, [rows].[dir], [rows].[addressMode], [rows].[nomencCity],');
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
      array_push ($q, 'SELECT [rows].wasteCodeNomenc, [rows].[dir],');
      array_push ($q, ' SUM([rows].quantityKG) as quantityKG,');
      array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
      array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
      array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    }

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [rows].personType = %i', $personType);

    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);

    if ($personType === 2)
    { // companies
		  array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].person, [rows].addressMode, [rows].personOffice, [rows].nomencCity, [rows].[dir]');
      array_push ($q, ' ORDER BY [rows].wasteCodeNomenc, persons.fullName');
    }
    else
    { // citizens
      array_push ($q, ' GROUP BY wasteCodeNomenc, [rows].[dir]');
      array_push ($q, ' ORDER BY [rows].wasteCodeNomenc');
    }

    $cnt = 0;
		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
      $personOid = '';
      $personICOB = '';
      $pn = '';
      $order = $r['itemId'].'_'.$r['dir'].'_';

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
        'oid' => $personOid,
        'pn' => $pn,
        'order' => $order,
        'id3' => $personICOB,
      ];

      if ($r['dir'] == WasteReturnEngine::rowDirIn)
      {
        if ($this->showUnits === 1)
          $item['quantityIn'] = round($r['quantityKG'] / 1000, 3);
        else
          $item['quantityIn'] = $r['quantityKG'];
      }
      elseif ($r['dir'] == WasteReturnEngine::rowDirOut)
      {
        if ($this->showUnits === 1)
          $item['quantityOut'] = round($r['quantityKG'] / 1000, 3);
        else
        $item['quantityOut'] = $r['quantityKG'];
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

            if (($r['id1'] && $r['id1'] !== ''))
              $item['id1'] = $r['id1'];
            if (($r['id2'] && $r['id2'] !== ''))
              $item['id2'] = $r['id2'];
          }
          else
          {
            $addr = $this->personMainAddress($r['person']);
            if ($addr)
            {
              $item['city'] = $addr['adrCity'];
              $item['street'] = $addr['adrStreet'];
              $item['zipCode'] = str_replace(' ', '', $addr['adrZipCode']);
            }
          }
        }
        else
        { // city
          $nomencCityRecData = $this->app()->loadItem($r['nomencCity'], 'e10.base.nomencItems');
          $item['id1'] = [
            ['text' => 'ORP: '.substr($nomencCityRecData['itemId'], 2), 'class' => ''],
          ];
          $item['id1'][0]['suffix'] = $nomencCityRecData['fullName'];
        }
      }

      $data[] = $item;
      $cnt++;
		}
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
    if ($dir == WasteReturnEngine::rowDirIn)
      $linkId = 'waste-suppliers-'.$this->calendarYear.'-'.$this->codeKindNdx;
    else
      $linkId = 'waste-cust-'.$this->calendarYear.'-'.$this->codeKindNdx;

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

  public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icontxt' => '∑', 'title' => 'Sumárně'];
    $d[] = ['id' => 'companiesIn', 'icon' => 'system/personCompany', 'title' => 'Firmy Příjem'];
    $d[] = ['id' => 'companiesOut', 'icon' => 'system/iconDelivery', 'title' => 'Firmy Výdej'];
    $d[] = ['id' => 'citizensSum', 'icon' => 'system/personHuman', 'title' => 'Občané'];
    $d[] = ['id' => 'citizensCities', 'icon' => 'system/iconMapMarker', 'title' => 'Občané podle obcí'];
    $d[] = ['id' => 'citizensCities2', 'icon' => 'system/iconMapMarker', 'title' => 'Občané podle obcí 2'];
    $d[] = ['id' => 'report', 'icon' => 'system/iconFile', 'title' => 'Hlášení'];

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
