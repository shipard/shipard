<?php

namespace e10pro\reports\waste_cz\libs;
use \Shipard\Utils\Utils;


class ReportWasteCompanies extends \e10doc\core\libs\reports\GlobalReport
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;

	public function init ()
	{
    $this->addParam ('calendarMonth', 'calendarPeriod', ['flags' => ['enableAll', 'quarters', 'halfs', 'years'], /*'years' => $this->tableProperty->propertyYears()*/]);

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
    array_push ($q, ' addrs.adrCity, addrs.id1');
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
      if ($r['person'] != $lastPerson)
      {
        $header = [
          'wasteCode' => ['text' => $r['personFullName'], 'docAction' => 'edit', 'pk' => $r['person'], 'table' => 'e10.persons.persons'],
          '_options' => [
            'colSpan' => ['wasteCode' => 4],
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

      if ($r['id1'] && $r['id1'] !== '')
        $item['id1'] = ['text' => $r['id1'], 'docAction' => 'edit', 'pk' => $r['personOffice'], 'table' => 'e10.persons.personsContacts'];

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
}