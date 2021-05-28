<?php
namespace wkf\core\libs;

class PersonsVGPersonsInGroup extends \lib\persons\PersonsVirtualGroup
{
	public function enumItems($columnId, $recData)
	{
		$enum = [];

		$allGroups = $this->app()->cfgItem ('e10.persons.groups');
		foreach ($allGroups as $gid => $g)
			$enum[$gid] = $g['name'];

		return $enum;
	}

	public function addPosts($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $vgRecData)
	{
		$q[] = 'SELECT personsGroups.person AS personNdx FROM [e10_persons_personsgroups] AS personsGroups';
		array_push ($q,' LEFT JOIN [e10_persons_persons] AS persons ON personsGroups.person = persons.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND personsGroups.group = %i', $vgRecData['virtualGroupItem']);
		array_push ($q,' AND persons.docStateMain <= %i', 2);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$emails = $this->personsEmails($r['personNdx']);

			foreach ($emails as $email)
			{
				$this->addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['personNdx'], $email);
			}
		}
	}
}
