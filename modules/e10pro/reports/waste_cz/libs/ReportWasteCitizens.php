<?php

namespace e10pro\reports\waste_cz\libs;
use \Shipard\Utils\Utils;


class ReportWasteCitizens extends \e10doc\core\libs\reports\GlobalReport
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
			case 'cities': $this->createContent_Cities (); break;
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

  public function createContent_Cities()
  {
    $q = [];

    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId,');
    array_push ($q, ' addrs.adrCity');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_personsContacts] AS addrs ON [rows].personOffice = addrs.ndx');
		array_push ($q, ' WHERE 1');
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

  public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icontxt' => '∑', 'title' => 'Sumárně'];
    $d[] = ['id' => 'cities', 'icon' => 'system/iconMapMarker', 'title' => 'Obce'];

		return $d;
	}
}