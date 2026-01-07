<?php

namespace e10pro\purchase\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \e10doc\core\libs\E10Utils;


/**
 * Class WasteInfoInCreator
 */
class WasteInfoInCreator extends Utility
{
  public function createAll($dateBegin, $dateEnd)
  {
    $this->db()->query('DELETE FROM e10pro_purchase_wasteInfo');


    $q = [];
    array_push($q, 'SELECT heads.*');
    array_push($q, ' FROM [e10doc_core_heads] AS [heads]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND heads.docType = %s', 'purchase');
    array_push($q, ' AND heads.docState = %i', 4000);
    array_push($q, ' AND heads.personType = %i', 2); // companies
    array_push($q, ' AND heads.dateAccounting >= %d', $dateBegin);
    array_push($q, ' AND heads.dateAccounting <= %d', $dateEnd);
    array_push($q, ' ORDER BY heads.[dateAccounting], heads.activateTimeFirst, heads.[docNumber]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $wasteInfoEngine = new WasteInfoEngine($this->app());
      $wasteInfoEngine->setDocument($r['ndx']);
      $wasteInfoEngine->loadData();
      foreach ($wasteInfoEngine->wasteCodes as $wc)
      {
        if ($this->app()->debug)
          echo "  - ".$r['docNumber'].' -> '.$wc['wc']." ".$wc['fullName']."\n";

        $addressMode = $r['otherAddress1Mode'];

        // -- existing?
        $q = [];
        array_push($q, 'SELECT wi.*');
        array_push($q, ' FROM [e10pro_purchase_wasteInfo] AS [wi]');
        array_push($q, ' WHERE 1');
        array_push($q, ' AND [person] = %i', $r['person']);
        array_push($q, ' AND [wasteCodeText] = %s', $wc['wc']);
        array_push($q, ' AND [addressMode] = %i', $addressMode);
        if ($addressMode === 0)
          array_push($q, ' AND [personOffice] = %i', $r['otherAddress1']);
        else
          array_push($q, ' AND [personNomencCity] = %i', $r['personNomencCity']);

        array_push($q, ' LIMIT 1');
        $existingWI = $this->db()->query($q)->fetch();
        if ($existingWI)
          continue;
        // -- create new
        $validTo = Utils::createDateTime($r['dateAccounting']);
        $validTo->add(new \DateInterval('P1Y'));
        $newWI = [
          'person' => $r['person'],
          'validFrom' => $r['dateAccounting'],
          //'validTo' => $validTo,
          'wasteCodeText' => $wc['wc'], 'wasteCodeNomenc' => $wc['wcNomenc'],
          'srcDocument' => $r['ndx'],
          'addressMode' => $addressMode,

          'owner' => $r['owner'],
          'ownerOffice' => $r['ownerOffice'],

          'docState' => 4000, 'docStateMain' => 2,
        ];

        if ($addressMode === 0)
        {
          $newWI['personOffice'] = $r['otherAddress1'];
        }
        else
        {
          $newWI['personNomencCity'] = $r['personNomencCity'];
        }

        $this->db()->query('INSERT INTO [e10pro_purchase_wasteInfo]', $newWI);
      }
    }
  }
}
