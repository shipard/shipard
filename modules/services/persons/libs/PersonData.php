<?php

namespace services\persons\libs;
use \Shipard\Base\Utility, \Shipard\Utils\Json;

class PersonData extends Utility
{
	var $personId = '';
  var $countryId = '';
  var $debug = 0;

  var $data = NULL;

  public function setPersonId($countryId, $personId)
  {
    $this->countryId;
    $this->personId = $personId;
  }

  protected function loadCoreData()
  {
		$q = [];
		array_push ($q, 'SELECT * FROM [services_persons_persons]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [oid] = %s', $this->personId);

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$p = ['ndx' => $r['ndx'], 'person' => $r->toArray(), 'address' => [], 'ids' => []];
			Json::polish($p['person']);
			// -- address
			$rowsAddr = $this->db()->query ('SELECT * FROM [services_persons_address] WHERE [person] = %i', $r['ndx']);
			foreach ($rowsAddr as $ra)
			{
				$raa = $ra->toArray();
				Json::polish($raa);
				$p['address'][] = $raa;
			}
			// -- ids
			$rowsIds = $this->db()->query ('SELECT * FROM [services_persons_ids] WHERE [person] = %i', $r['ndx']);
			foreach ($rowsIds as $rid)
			{
				$rida = $rid->toArray();
				Json::polish($rida);
				$p['ids'][] = $rida;
			}

			$this->data = $p;
			break;
		}
  }

  public function load()
  {
    $this->loadCoreData();
  }
}

