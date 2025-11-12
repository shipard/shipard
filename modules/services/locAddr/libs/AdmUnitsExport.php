<?php

namespace services\locAddr\libs;
use \Shipard\Base\Utility, \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class AdmUnitsExport
 */
class AdmUnitsExport extends Utility
{
  var $country = 60;

  var $data = [];

  public function export()
  {
    $q = [];
		array_push ($q, ' SELECT [laUnits].*, ');
		array_push ($q, ' [laUnits2].[laUnitId] AS [laUnitOwner2Id],');
		array_push ($q, ' [laUnits1].[laUnitId] AS [laUnitOwner1Id],');
		array_push ($q, ' [laUnits0].[laUnitId] AS [laUnitOwner0Id],');
		array_push ($q, ' [laUnits10].[laUnitId] AS [laUnitOwner10Id]');
		array_push ($q, ' FROM [services_locAddr_laUnits] AS [laUnits]');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits2] ON laUnits.laUnitOwner2 = laUnits2.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits1] ON laUnits.laUnitOwner1 = laUnits1.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits0] ON laUnits.laUnitOwner0 = laUnits0.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits10] ON laUnits.laUnitOwner10 = laUnits10.ndx');
    array_push ($q, ' ORDER BY [level], [fullName]');
    $rows = $this->app()->db()->query($q);

    $units = [];
    foreach ($rows as $r)
    {
      $unit = [
        'id' => $r['laUnitId'],
        'fn' => $r['fullName'],
        'level' => $r['level'],
        'wgs84lat' => $r['wgs84lat'],
        'wgs84lng' => $r['wgs84lng'],
      ];

      if ($r['validFrom'])
        $unit['validFrom'] = $r['validFrom']->format('Y-m-d');
      if ($r['validTo'])
        $unit['validTo'] = $r['validTo']->format('Y-m-d');

      if ($r['laUnitOwner0Id'])
        $unit['owner0'] = $r['laUnitOwner0Id'];
      if ($r['laUnitOwner1Id'])
        $unit['owner1'] = $r['laUnitOwner1Id'];
      if ($r['laUnitOwner2Id'])
        $unit['owner2'] = $r['laUnitOwner2Id'];
      if ($r['laUnitOwner10Id'])
        $unit['owner10'] = $r['laUnitOwner10Id'];
      if ($r['municipalityPersonOid'] && $r['municipalityPerson'])
        $unit['municipalityPersonOid'] = $r['municipalityPersonOid'];

      $units[] = $unit;
    }

    $this->data = $units;
  }
}
