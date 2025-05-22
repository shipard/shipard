<?php

namespace e10pro\reports\waste_cz\libs;


use \Shipard\Utils\Utils;
use \Shipard\Utils\World;
use \e10pro\reports\waste_cz\libs\WasteReturnEngine;

/**
 * class ReportWasteCitizens
 */
class ReportWasteCitizens extends \e10doc\core\libs\reports\GlobalReport
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;
  var $calendarYear = 0;
  var $persons = [];
  var $showUnits = -1;
  var $codeKindNdx = 0;
  var $useZipCode = 0;
  var $limitDistance = 0;
  var $limitKG = 0;
  var $officeLat = 0.0;
  var $officeLon = 0.0;

  var $thisCountryNdx = 60;

  var $partners = [];
  var $wastes = [];

	public function init ()
	{
    $today = Utils::today();
    $defaultYear = 'Y'.(intval($today->format('Y')) - 1);
    $this->addParam ('calendarMonth', 'calendarPeriod', ['flags' => ['quarters', 'halfs', 'years'], 'defaultValue' => $defaultYear]);

    $ckEnum = $this->codesKindEnum();
    $this->addParam('switch', 'codeKind', ['title' => 'Druh', 'switch' => $ckEnum, 'radioBtn' => 1, '__defaultValue' => 'all']);

    //if ($this->subReportId === 'report')
    //  $this->addParam('switch', 'showUnits', ['title' => 'Jednotka', 'switch' => ['1' => 'Tuny', '0' => 'kg'], 'radioBtn' => 1, 'defaultValue' => '1']);


    if ($this->subReportId === 'citizensCities2')
    {
      $this->addParam('switch', 'useZipCode', ['title' => 'PSČ', 'switch' => ['0' => 'Ne', '1' => 'Ano'], 'radioBtn' => 1, 'defaultValue' => '0']);
      $this->addParam('switch', 'limitDistance', ['title' => 'Omezit vzdálenost', 'switch' => ['0' => 'Ne', '10' => '10 km', '15' => '15 km', '20' => '20 km', '30' => '30 km', '40' => '40 km', '50' => '50 km', '60' => '60 km', '70' => '70 km', '80' => '80 km', '90' => '90 km', '100' => '100 km'], 'defaultValue' => '0']);
      $this->addParam('switch', 'limitKG', ['title' => 'Limit', 'switch' => ['0' => 'Ne', '100' => '100 kg', '250' => '250 kg', '500' => '500 kg', '1000' => '1 tuna', '5000' => '5 tun'], 'defaultValue' => '0']);
    }

		parent::init();

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
			case 'citizensSum': $this->createContent_CitizensSum (); break;
			case 'citizensCities': $this->createContent_CitizensCities (); break;
      case 'citizensCities2': $this->createContent_CitizensCities2 (); break;
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

		$h = ['wasteCode' => ' Kód odpadu', 'wasteName' => 'Text', 'quantity' => '+Množství [kg]'];
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

  public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icontxt' => '∑', 'title' => 'Sumárně'];
    $d[] = ['id' => 'citizensSum', 'icon' => 'system/personHuman', 'title' => 'Občané'];
    $d[] = ['id' => 'citizensCities', 'icon' => 'system/iconMapMarker', 'title' => 'Občané podle obcí'];
    $d[] = ['id' => 'citizensCities2', 'icon' => 'system/iconMapMarker', 'title' => 'Občané podle obcí 2'];

		return $d;
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
