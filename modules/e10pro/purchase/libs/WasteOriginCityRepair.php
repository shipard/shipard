<?php

namespace e10pro\purchase\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \Shipard\Utils\Wgs84;


/**
 * Class WasteOriginCityRepair
 */
class WasteOriginCityRepair extends Utility
{
  public function repairAll($wasteReturnNdx)
  {
    $wasteReturnRecData = $this->app()->loadItem($wasteReturnNdx, 'e10doc.waster.wasteReturns');
    if (!$wasteReturnRecData)
    {
      error_log("ERROR: waste return #$wasteReturnNdx not found!\n");
      return;
    }

    $ownerOffice = $this->app()->loadItem($wasteReturnRecData['personOffice'], 'e10.persons.personsContacts');
    if (!$ownerOffice)
    {
      error_log("ERROR: owner office #{$wasteReturnRecData['personOffice']} not found!\n");
      return;
    }

    $year = $wasteReturnRecData['year'];
    $dateBegin = Utils::createDateTime("$year-01-01");
    $dateEnd = Utils::createDateTime("$year-12-31");

    $q = [];
    array_push($q, 'SELECT heads.*');
    array_push($q, ' FROM [e10doc_core_heads] AS [heads]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND heads.docType = %s', 'purchase');
    array_push($q, ' AND heads.docState = %i', 4000);
    //array_push($q, ' AND heads.personType = %i', 2); // companies
    array_push($q, ' AND heads.dateAccounting >= %d', $dateBegin);
    array_push($q, ' AND heads.dateAccounting <= %d', $dateEnd);
    array_push($q, ' ORDER BY heads.[dateAccounting], heads.activateTimeFirst, heads.[docNumber]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      echo "* ".$r['docNumber']."\n";

      if ($r['personType'] === 1)
      { // citizen
        $wasteOriginAdmUnitNdx = 0;
        if (!$r['wasteOriginAdmUnit'])
        {
          $addr = NULL;
          if ($r['deliveryAddress'] ?? 0)
            $addr = $this->app()->loadItem($r['deliveryAddress'], 'e10.persons.personsContacts');
          elseif ($r['otherAddress1'])
            $addr = $this->app()->loadItem($r['otherAddress1'], 'e10.persons.personsContacts');
          elseif ($r['otherAddress2'])
            $addr = $this->app()->loadItem($r['otherAddress2'], 'e10.persons.personsContacts');
          if ($addr !== NULL)
          {
            if ($addr['adrCountry'] !== 60)
            { // outside CZ
              $addr = $this->app()->loadItem($r['ownerOffice'], 'e10.persons.personsContacts');
              if ($addr !== NULL)
                $wasteOriginAdmUnitNdx = $addr['saAdmUnit11Ndx'];
            }
            else
              $wasteOriginAdmUnitNdx = $addr['saAdmUnit11Ndx'];

            // check distance
            if ($wasteReturnRecData['muniDistanceLimit'])
            {
              $admUnitRecData = $this->app()->loadItem($wasteOriginAdmUnitNdx, 'e10.world.admUnits');
              if ($admUnitRecData)
              {
                $distance = intval(round(Wgs84::computeDistance($ownerOffice['adrLocLat'], $ownerOffice['adrLocLon'], $admUnitRecData['wgs84lat'], $admUnitRecData['wgs84lng']) / 1000, 0));
                if ($distance > $wasteReturnRecData['muniDistanceLimit'])
                {
                  //echo "  -- distance $distance km exceeds limit ".$wasteReturnRecData['muniDistanceLimit']." km, skipping\n";
                  $wasteOriginAdmUnitNdx = $ownerOffice['saAdmUnit11Ndx'];
                }
              }
            }
          }
        }
        else
        { // check existing max distance
          if ($wasteReturnRecData['muniDistanceLimit'])
          {
            $admUnitRecData = $this->app()->loadItem($r['wasteOriginAdmUnit'], 'e10.world.admUnits');
            if ($admUnitRecData)
            {
              $distance = intval(round(Wgs84::computeDistance($ownerOffice['adrLocLat'], $ownerOffice['adrLocLon'], $admUnitRecData['wgs84lat'], $admUnitRecData['wgs84lng']) / 1000.0, 0));
              if ($distance > $wasteReturnRecData['muniDistanceLimit'])
              {
                //echo "  -- distance $distance km exceeds limit ".$wasteReturnRecData['muniDistanceLimit']." km, skipping\n";
                $wasteOriginAdmUnitNdx = $ownerOffice['saAdmUnit11Ndx'];
              }
            }
          }
        }

        if ($wasteOriginAdmUnitNdx)
          $this->db()->query('UPDATE e10doc_core_heads SET wasteOriginAdmUnit = %i ', $wasteOriginAdmUnitNdx, ' WHERE ndx = %i', $r['ndx']);
      }
    }
  }
}
