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
			if ($this->recipientsMode === self::rmPersonsMsgs)
			{
				$this->addRecipientPerson ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['personNdx']);
				continue;
			}

			$emails = $this->personsEmails($r['personNdx']);
			foreach ($emails as $email)
			{
				$this->addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['personNdx'], $email);
			}
		}
	}

	public function addPostFromDocLinks($bulkDstTable, $bulkOwnerColumnId, $bulkOwnerNdx,
																			$linkId, $srcTableId, $srcRecId)
	{
		$rows = $this->app()->db->query (
			'SELECT doclinks.* FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', $linkId,
			//' AND dstTableId = %s', $dstTableId,
			' AND srcTableId = %s', $srcTableId,
			' AND doclinks.srcRecId = %i', $srcRecId
		);
		foreach ($rows as $r)
		{
			if ($r['dstTableId'] === 'e10.persons.groups')
			{
				$this->addPersonsGroupRecipients($bulkDstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['dstRecId']);
			}
			else
			{
				if ($this->recipientsMode === self::rmPersonsMsgs)
				{
					$this->addRecipientPerson ($bulkDstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['dstRecId']);
					continue;
				}

				$emails = $this->personsEmails($r['personNdx']);
				foreach ($emails as $email)
				{
					$this->addPostEmail ($bulkDstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['dstRecId'], $email);
				}

			}
		}
	}

	public function addPersonsGroupRecipients($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $personGroupNdx)
	{
		$q[] = 'SELECT personsGroups.person AS personNdx FROM [e10_persons_personsgroups] AS personsGroups';
		array_push ($q,' LEFT JOIN [e10_persons_persons] AS persons ON personsGroups.person = persons.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND personsGroups.group = %i', $personGroupNdx);
		array_push ($q,' AND persons.docStateMain <= %i', 2);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($this->recipientsMode === self::rmPersonsMsgs)
			{
				$this->addRecipientPerson ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['personNdx']);
				continue;
			}

			$emails = $this->personsEmails($r['personNdx']);
			foreach ($emails as $email)
			{
				$this->addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $r['personNdx'], $email);
			}
		}
	}
}
