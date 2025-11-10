<?php

namespace e10pro\purchase\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \e10doc\core\libs\E10Utils;


/**
 * Class WasteOriginCityRepair
 */
class WasteOriginCityRepair extends Utility
{
  public function repairAll($dateBegin, $dateEnd)
  {
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
        $wasteOriginAdmUnit = 0;
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
                $wasteOriginAdmUnit = $addr['saAdmUnit11Ndx'];
            }
            else
              $wasteOriginAdmUnit = $addr['saAdmUnit11Ndx'];
          }

          if ($wasteOriginAdmUnit)
            $this->db()->query('UPDATE e10doc_core_heads SET wasteOriginAdmUnit = %i WHERE ndx = %i', $wasteOriginAdmUnit, $r['ndx']);
        }
      }
    }
  }
}
