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
    array_push ($q, ' admUnits.fullName AS admUnitFullName, admUnits.admUnitId AS admUnitId');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    array_push ($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');
    array_push ($q, ' LEFT JOIN [e10_world_admUnits] AS admUnits ON [heads].wasteOriginAdmUnit = admUnits.ndx');

    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);
		array_push ($q, ' AND [rows].personType = %i', 1);
    array_push ($q, ' AND [rows].[dir] = %i', 0);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);

    array_push ($q, ' GROUP BY admUnits.admUnitId, rows.wasteHandlingCode, wasteCodeNomenc');
    array_push ($q, ' ORDER BY admUnits.admUnitId, rows.wasteHandlingCode, wasteCodeNomenc');

		$rows = $this->app->db()->query ($q);
    $header = ['#' => '#', 'cityCounter' => ' ##', 'cityName' => 'Obec', 'natCityId' => '_IČZUJ', 'hc' => 'Kód nak.', 'wasteCode' => 'Kat. č. odpadu', 'quantityKG' => '+Množství (kg)'];

    $cityCounter = 0;
    $cityStripCounter = 0;
    $lastNatCityId = '_____';
		$data = [];
		forEach ($rows as $r)
		{
      $natCityId = strval($r['admUnitId']);
      $wasteCode = strval($r['itemId']);
      $cityName = $r['admUnitFullName'];

      $rowId = $natCityId.'_'.'_'.$wasteCode.'_'.strval($r['wasteHandlingCode']);
      if (!isset($data[$rowId]))
      {
        $data[$rowId] = [
          'cityName' => $cityName,
          'natCityId' => $natCityId,
          'wasteCode' => $wasteCode,
          'wasteCodeNomenc' => $r['wasteCodeNomenc'],
          'wasteName' => $r['fullName'] ?? '???',
          'quantityKG' => $r['quantityKG'],
          'hc' => $r['wasteHandlingCode'],
        ];

        if ($lastNatCityId !== $r['admUnitId'])
        {
          $cityCounter++;
          $lastNatCityId = $r['admUnitId'];
          $data[$rowId]['cityCounter'] = $cityCounter;
          $data[$rowId]['_options']['class'] = 'e10-row-this';
          $data[$rowId]['_options']['cellClasses']['cityName'] = 'e10-bold';
          $cityStripCounter = 0;
        }

        if ($cityStripCounter && $cityStripCounter % 2 == 0)
          $data[$rowId]['_options']['class'] = 'e10-bg-t9';

      }
      else
        $data[$rowId]['quantityKG'] += $r['quantityKG'];

      $cityStripCounter++;
		}

    $this->dataCities = $data;
    $this->headerCities = $header;
  }

  public function createMunicipalityData()
  {
    $this->municipalityData = [];

    $cityCounter = 0;
    $lastNatCityId = '_____';
    foreach ($this->dataCities as $rowId => $rr)
    {
      if ($lastNatCityId !== $rr['natCityId'])
      {
        $cityCounter++;
        $lastNatCityId = $rr['natCityId'];
      }

      $item = [
        'hc' => $rr['hc'],
        'cityName' => $rr['cityName'].'!',
        'iczuj' => $rr['natCityId'] ?? '',
        'cityCounter' => $cityCounter,
        'wasteCode' => strval($rr['wasteCode']),
        'wasteCodeNomenc' => $rr['wasteCodeNomenc'],
        'wasteName' => $rr['wasteName'] ?? '---',
        'quantity' => round($rr['quantityKG'] / 1000, 6),
        'order' => $rr['wasteCode'].'_'.'0'.'_'.'98'.'_'.'8888888',
      ];
      $this->municipalityData[] = $item;
    }
  }

  protected function cityById_TMP($cityId)
  {
    $nc = $this->db()->query('SELECT * FROM e10_base_nomencItems WHERE itemId = %s', 'CZ'.$cityId)->fetch();
    if ($nc)
    {
      return $nc['shortName'];
    }

    return '';
  }
}
