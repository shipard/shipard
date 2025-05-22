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
  var $showUnits = -1;
  var $codeKindNdx = 0;
  var $partners = [];
  var $wastes = [];

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

		parent::init();

    if ($this->sendStatus === '')
      $this->sendStatus = $this->reportParams ['sendStatus']['value'] ?? 'all';

    $this->showUnits = intval($this->reportParams ['showUnits']['value'] ?? '0');

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

  public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icontxt' => '∑', 'title' => 'Sumárně'];
    $d[] = ['id' => 'companiesIn', 'icon' => 'system/personCompany', 'title' => 'Firmy Příjem'];
    $d[] = ['id' => 'companiesOut', 'icon' => 'system/iconDelivery', 'title' => 'Firmy Výdej'];

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
}
