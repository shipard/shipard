<?php

namespace e10pro\reports\waste_cz\libs;
use \Shipard\Utils\Utils;


/**
 * class ReportWasteCompanies
 */
class ReportWasteCompanies extends \e10doc\core\libs\reports\GlobalReport
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;
  var $calendarYear = 0;
  var $persons = [];

	public function init ()
	{
    $today = Utils::today();
    $defaultYear = 'Y'.(intval($today->format('Y')) - 1);
    $this->addParam ('calendarMonth', 'calendarPeriod', ['flags' => ['quarters', 'halfs', 'years'], 'defaultValue' => $defaultYear]);

		parent::init();

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
			case 'persons': $this->createContent_Persons (); break;
		}
	}

  public function createContent_Sum()
  {
    $q = [];

    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [rows].personType = %i', 2);
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

		$this->setInfo('title', 'Celkové odběry odpadů od firem');
  }

  public function createContent_Persons()
  {
    $q = [];

    array_push ($q, 'SELECT [rows].person, [rows].personOffice, [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId,');
    array_push ($q, ' persons.fullName AS personFullName,');
    array_push ($q, ' addrs.adrCity, addrs.adrStreet, addrs.id1');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_personsContacts] AS addrs ON [rows].personOffice = addrs.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON [rows].person = persons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [rows].personType = %i', 2);
    array_push ($q, ' AND [rows].[dir] = %i', 0);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);
		array_push ($q, ' GROUP BY [rows].person, [rows].personOffice, wasteCodeNomenc');
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
          'title' => 'Načíst pobočky',
          'class' => 'pull-right',
          'btnClass' => 'btn btn-xs btn-primary pull-right',
          'data-class' => 'e10.persons.libs.register.AddOfficesWizard',
          'table' => 'e10.persons.persons',
          'data-addparams' => 'personNdx='.$r['person'],
          //'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid,
        ];

        $header['wasteCode'][] = [
          'text' => 'Nastavit provozovnu', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/personCompany',
          'title' => 'Načíst pobočky',
          'class' => 'pull-right',
          'btnClass' => 'btn btn-xs btn-success pull-right',
          'data-class' => 'e10pro.reports.waste_cz.libs.SetOfficeWizard',
          'table' => 'e10.persons.persons',
          'data-addparams' => 'personNdx='.$r['person'],
          //'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid,
        ];

        // -- print button
        $btn = ['type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'system/actionPrint', 'text' => 'Přehled',
          'data-report' => 'e10pro.reports.waste_cz.ReportWasteOnePerson',
          'data-table' => 'e10.persons.persons', 'data-pk' => $r['person'],
          'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
          'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
          'data-param-calendar-year' => strval($this->calendarYear),
          'actionClass' => 'btn-xs', 'class' => 'pull-right'];
        $btn['subButtons'] = [];
        $btn['subButtons'][] = [
          'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconEmail', 'title' => 'Odeslat emailem', 'btnClass' => 'btn-default btn-xs',
          'data-table' => 'e10.persons.persons', 'data-pk' => $r['person'],
          'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
          'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
          'data-param-calendar-year' => strval($this->calendarYear),
          'data-class' => 'Shipard.Report.SendFormReportWizard',
          'data-addparams' => 'reportClass=' . 'e10pro.reports.waste_cz.ReportWasteOnePerson' . '&documentTable=' . 'e10.persons.persons'
        ];
        $header['wasteCode'][] = $btn;


        $header['_options']['beforeSeparator'] = 'separator';

        $data[] = $header;
      }
      $item = [
        'wasteCode' => $r['itemId'],
        'wasteName' => $r['fullName'],
        'quantity' => $r['quantityKG'],
      ];

      if ($r['id1'] && $r['id1'] !== '')
      {
        $item['id1'] = ['text' => $r['id1'], 'docAction' => 'edit', 'pk' => $r['personOffice'], 'table' => 'e10.persons.personsContacts'];
        $item['id1']['prefix'] = $r['adrStreet'].', '.$r['adrCity'];
      }
			$data[] = $item;

      $lastPerson = $r['person'];
		}

		$h = ['wasteCode' => 'Kód odpadu', 'wasteName' => 'Text', 'id1' => ' IČP', 'quantity' => ' Množství [kg]'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

		$this->setInfo('title', 'Odběr odpadů od firem');
  }

  public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icontxt' => '∑', 'title' => 'Sumárně'];
    $d[] = ['id' => 'persons', 'icon' => 'system/personCompany', 'title' => 'Firmy'];

		return $d;
	}

	public function createToolbar ()
	{
		$buttons = parent::createToolbar();
		$buttons[] = [
			'text' => 'Rozeslat hromadně emailem', 'icon' => 'system/iconEmail',
			'type' => 'action', 'action' => 'addwizard', 'data-class' => 'e10pro.reports.waste_cz.ReportWasteOnePersonWizard',
      'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
      'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
      'data-param-calendar-year' => strval($this->calendarYear),
      'data-table' => 'e10.persons.persons', 'data-pk' => '0',
			'class' => 'btn-primary'
		];
		return $buttons;
	}
}