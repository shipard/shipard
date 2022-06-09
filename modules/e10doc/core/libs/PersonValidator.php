<?php

namespace e10doc\core\libs;
use \Shipard\Base\Utility;

/**
 * @class PersonValidator
 */
class PersonValidator extends Utility
{
  var $baseServicesURL = 'https://data.shipard.org/';

  public function batchCheck()
  {
    $maxOldDate = new \DateTime('1 year ago');

    $q[] = 'SELECT * FROM [e10_persons_persons] as persons ';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docState] = %i', 4000);
    array_push($q, ' AND [company] = %i', 1);

    array_push($q, ' AND EXISTS (SELECT person FROM e10doc_core_heads WHERE persons.ndx = person AND dateAccounting > %d', $maxOldDate,
        ' AND docType IN %in', ['invno', 'invni', 'cash', 'cashreg'], ')');
    
    array_push($q, ' AND EXISTS (SELECT recid FROM e10_persons_address WHERE persons.ndx = recid',
        ' AND tableid = %s', 'e10.persons.persons', 
        ' AND country = %s', 'cz', 
        ')');

		array_push($q, ' LIMIT 0, 500');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $oidRecData = $this->db()->query('SELECT [valueString] FROM [e10_base_properties] WHERE [tableid] = %s', 'e10.persons.persons', 
                            ' AND [recid] = %i', $r['ndx'], 
                            ' AND  [property] = %s', 'oid', ' AND [group] = %s', 'ids')->fetch();

      if (!$oidRecData)
        continue;
      
      $oid = $oidRecData['valueString'];

      $url = $this->baseServicesURL.'persons/cz/'.$oid.'/json';

      $resultDataStr = file_get_contents($url);
      $resultData = json_decode($resultDataStr, TRUE);
      if (!$resultData || !isset($resultData['status']) || !$resultData['status'])
      {
        echo "\n#{$r['ndx']}: `{$oid}` {$r['fullName']} "."\n";
        echo "  --> ".$url."\n";
        echo "    ### ERROR ### \n".$resultDataStr."    ### ^^^^^ ### \n\n";
      }

      echo ".";
      sleep(5);
		}
  }
}