<?php

namespace e10doc\waster\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \e10doc\core\libs\E10Utils;


/**
 * Class MuniReportsCreator
 */
class MuniReportsCreator extends Utility
{
  public function createAll($wasteReturnNdx)
  {
    if ($this->app()->debug)
      echo "Creating municipal reports for waste return #$wasteReturnNdx...\n";

    $wasteReturnRecData = $this->app()->loadItem($wasteReturnNdx, 'e10doc.waster.wasteReturns');
    if (!$wasteReturnRecData)
    {
      error_log("ERROR: waste return #$wasteReturnNdx not found!\n");
      return;
    }

    $year = $wasteReturnRecData['year'];

    $dateBegin = Utils::createDateTime("$year-01-01");
    $dateEnd = Utils::createDateTime("$year-12-31");
    $codeKindNdx = 1;

    //$this->db()->query('DELETE FROM e10doc_waster_muniReports');

    $q = [];
    array_push ($q, 'SELECT SUM([rows].quantityKG) as quantityKG, heads.wasteOriginAdmUnit');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $codeKindNdx);
		array_push ($q, ' AND [rows].personType = %i', 1);
    array_push ($q, ' AND [rows].[dir] = %i', 0);
    array_push ($q, ' AND [heads].[docState] = %i', 4000);
    array_push ($q, ' AND [rows].[dateAccounting] >= %d', $dateBegin);
    array_push ($q, ' AND [rows].[dateAccounting] <= %d', $dateEnd);
    array_push ($q, ' GROUP BY heads.wasteOriginAdmUnit');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $admUnitNdx = $r['wasteOriginAdmUnit'];
      if ($admUnitNdx == 0)
        continue;
      if ($this->app()->debug)
        echo "  - ".$admUnitNdx."\n";

      $admUnitRecData = $this->app()->loadItem($admUnitNdx, 'e10.world.admUnits');
      $muniPersonOid = $admUnitRecData['municipalityPersonOid'] ?? '';

      // -- existing?
      $q = [];
      array_push($q, 'SELECT mr.*');
      array_push($q, ' FROM [e10doc_waster_muniReports] AS [mr]');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [wasteOriginAdmUnit] = %i', $admUnitNdx);
      array_push($q, ' AND [wasteReturn] = %i', $wasteReturnNdx);
      array_push($q, ' LIMIT 1');
      $existingMR = $this->db()->query($q)->fetch();

      if ($existingMR)
      { // UPDATE
        //continue;
      }
      else
      { // -- create new
        $newMR = [
          'wasteOriginAdmUnit' => $admUnitNdx,
          'wasteReturn' => $wasteReturnNdx,
          'docState' => 4000, 'docStateMain' => 2,
        ];

        if (isset($admUnitRecData['municipalityPerson']) && $admUnitRecData['municipalityPerson'] != 0)
          $newMR['muniPerson'] = $admUnitRecData['municipalityPerson'];
        else
        {
          if ($muniPersonOid !== '')
          {
            $personNdx = $this->addPerson($muniPersonOid);
            $newMR['muniPerson'] = $personNdx;

            // -- save back to admUnit
            $this->db()->query('UPDATE [e10_world_admUnits] SET [municipalityPerson] = %i WHERE [ndx] = %i',
              $personNdx, $admUnitNdx);
          }
        }

        $this->db()->query('INSERT INTO [e10doc_waster_muniReports]', $newMR);
      }
    }
  }

  protected function addPerson($oid)
  {
    $existedPersonNdx = $this->searchPerson('ids', 'oid', $oid);
    if ($existedPersonNdx)
      return $existedPersonNdx;

    $reg = new \e10\persons\libs\register\PersonRegister($this->app());
    $reg->addDocState = 4000;
    $reg->addDocStateMain = 2;
    $reg->addPerson($oid);
    $personNdx = $reg->personNdx;
    return $personNdx;
  }

	protected function searchPerson($group, $id, $value)
	{
		$q[] = 'SELECT props.recid';

		array_push ($q,	' FROM [e10_base_properties] AS props');
		array_push ($q,	' LEFT JOIN [e10_persons_persons] AS persons ON props.recid = persons.ndx');
		array_push ($q,	' WHERE 1');
		array_push ($q,	' AND [tableid] = %s', 'e10.persons.persons', ' AND [valueString] = %s', $value);
		array_push ($q,	' AND [group] = %s', $group, ' AND property = %s', $id);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			return $r['recid'];
		}

		return 0;
	}
}
