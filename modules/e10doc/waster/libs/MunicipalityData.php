<?php
namespace e10doc\waster\libs;
use \Shipard\Base\Utility;


/**
 * class MunicipalityData
 */
class MunicipalityData extends Utility
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;
  var $codeKindNdx = 1;

  var $officeLat = 0.0;
  var $officeLon = 0.0;
  var $thisCountryNdx = 60;

  var $limitDistance = 0;
  var $limitKG = 0;

  var $dataCities = [];
  var $headerCities = NULL;
  var $municipalityData = [];


  public function loadData()
  {
    $this->loadFromDb();
  }

  public function loadFromDb()
  {
    $q = [];
    array_push ($q, 'SELECT [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG, rows.wasteHandlingCode,');
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

    array_push ($q, ' GROUP BY addrs.adrCountry, addrs.adrCity, wasteCodeNomenc');

    array_push ($q, ' ORDER BY addrs.adrCountry, addrs.adrCity, wasteCodeNomenc');

		$rows = $this->app->db()->query ($q);
    $header = ['#' => '#', 'city' => 'Obec', 'dist' => ' Vzdál. KM', 'natCityId' => '_IČZUJ', 'wasteHandlingCode' => 'Kód nak.'];

		$data = [];
		forEach ($rows as $r)
		{
      $natCityId = '';
      $wasteCode = strval($r['itemId']);

      $cityName = $r['adrCity'];

      if ($this->thisCountryNdx === $r['adrCountry'])
      {
        $natCityId = $this->natCityId($r['adrCity']);
      }
      else
      {
        $natCityId = 585068;
      }

      $rowId = $natCityId.'_'.'_'.$wasteCode.'_'.strval($r['wasteHandlingCode']);
      if (!isset($data[$rowId]))
      {
        $data[$rowId] = [
          'cityName' => $this->cityById($cityName),
          'natCityId' => $natCityId,
          'wasteCode' => $wasteCode,
          'quantityKG' => $r['quantityKG'],
          'hc' => $r['wasteHandlingCode']
        ];
      }
      else
        $data[$rowId]['quantityKG'] += $r['quantityKG'];
		}

    $this->dataCities = $data;
    $this->headerCities = $header;
  }

  public function createMunicipalityData()
  {
    $this->municipalityData = [];

    foreach ($this->dataCities as $rowId => $rr)
    {
      $item = [
        'hc' => $rr['hc'],
        'cityName' => $rr['cityName'],
        'iczuj' => $rr['natCityId'] ?? '',
        'wasteCode' => strval($rr['wasteCode']),
        'quantity' => round($rr['quantityKG'] / 1000, 6),
        'order' => $rr['wasteCode'].'_'.'0'.'_'.$rr['hc'].'_',
      ];
      $this->municipalityData[] = $item;
    }
  }

  function computeDistance($lat1, $lng1, $lat2, $lng2, $radius = 6378137)
  {
    static $x = M_PI / 180;
    $lat1 *= $x; $lng1 *= $x;
    $lat2 *= $x; $lng2 *= $x;
    $distance = 2 * asin(sqrt(pow(sin(($lat1 - $lat2) / 2), 2) + cos($lat1) * cos($lat2) * pow(sin(($lng1 - $lng2) / 2), 2)));

    return $distance * $radius;
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
}
