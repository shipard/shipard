<?php

namespace e10pro\soci\libs;

use \Shipard\Utils\Utils;


/**
 * class PersonsVGStudents
 */
class PersonsVGStudents extends \lib\persons\PersonsVirtualGroup
{
	public function enumItems($columnId, $recData)
	{
		if ($columnId === 'virtualGroupItem')
			return $this->woEventsTypes();

		if ($columnId === 'virtualGroupItem2')
			return $this->woEventsPlaces();

		return [];
	}

  protected function woEventsTypes()
  {
    $enum = [];
    $woKinds = $this->app()->cfgItem('e10mnf.workOrders.kinds');
    foreach ($woKinds as $wokId => $wok)
    {
      $enum[$wokId] = $wok['fullName'];
    }

    return $enum;
  }

  protected function woEventsPlaces()
  {
    $enum = [];
		$enum['0'] = '-- všecha místa --';
		$activePeriodNdx = 'AY'.'1';

		$q = [];
		array_push ($q, 'SELECT wo.*,');
		array_push ($q, ' places.fullName AS placeFullName, places.shortName AS placeShortName, places.id AS placeId');
		array_push ($q, ' FROM [e10mnf_core_workOrders] AS wo');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON wo.place = places.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND wo.docState IN %in', [1200, 8000]);
		array_push ($q, ' AND wo.usersPeriod = %s', $activePeriodNdx);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!$r['place'])
				continue;

			if (!isset($enum[$r['place']]))
				$enum[$r['place']] = $r['placeFullName'];
		}

    return $enum;
  }

	public function addPosts($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $vgRecData)
	{
		$activePeriodNdx = 1;
		$today = utils::today('', $this->app());

		$q[] = 'SELECT [entries].*,';
		array_push($q, ' [persons].fullName AS personFullName, [persons].id AS personId');
		array_push($q, ' FROM [e10pro_soci_entries] AS [entries]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [entries].[dstPerson] = [persons].[ndx]');
		array_push ($q, 'LEFT JOIN e10mnf_core_workOrders AS workOrders ON entries.entryTo = workOrders.ndx ');
		array_push($q, ' WHERE 1');

		array_push($q, ' AND [entries].entryPeriod = %i', $activePeriodNdx);
		array_push($q, ' AND [entries].dstPerson != %i', 0);
		array_push($q, ' AND [entries].entryState = %i', 0);

		if ($vgRecData['virtualGroupItem'])
			array_push($q, ' AND [workOrders].docKind = %i', $vgRecData['virtualGroupItem']);

		if ($vgRecData['virtualGroupItem2'])
			array_push($q, ' AND [workOrders].place = %i', $vgRecData['virtualGroupItem2']);

		array_push($q, ' AND [entries].docState != %i', 9800);
		array_push($q, ' ORDER BY persons.fullName, entries.ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($this->recipientsMode === self::rmPersonsMsgs)
			{
				if (!intval($r['dstPerson']))
					continue;

				$this->addRecipientPerson ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['dstPerson']);
				continue;
			}

			$emails = $this->personsEmails($r['dstPerson']);
			foreach ($emails as $email)
			{
				$this->addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['dstPerson'], $email);
			}
		}
	}
}
