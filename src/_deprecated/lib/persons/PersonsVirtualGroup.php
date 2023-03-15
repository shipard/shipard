<?php

namespace lib\persons;

use e10\Utility;


/**
 * Class PersonsVirtualGroup
 * @package lib\persons
 */
class PersonsVirtualGroup extends Utility
{
	var $vgItemNdx = 0;

	public function setItem ($vgItemNdx)
	{
		$this->vgItemNdx = $vgItemNdx;
	}

	public function enumItems($columnId, $recData)
	{
		return [];
	}

	public function addPosts($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $vgRecData)
	{
	}

	function emailExist ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $email)
	{
		$exist = $this->db()->query ('SELECT ndx FROM ['.$dstTable->sqlName().'] WHERE [email] = %s', $email, ' AND ['.$bulkOwnerColumnId.'] = %i', $bulkOwnerNdx)->fetch();
		if ($exist)
			return TRUE;

		return FALSE;
	}

	function addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $personNdx, $email)
	{
		if (strstr ($email, '@') === FALSE)
			return;

		if ($this->emailExist($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $email))
			return;

		$newPost = ['email' => $email, 'person' => $personNdx, $bulkOwnerColumnId => $bulkOwnerNdx];
		$this->db()->query ('INSERT INTO ['.$dstTable->sqlName().']', $newPost);
	}

	function personsEmails($personNdx)
	{
		$q[] = 'SELECT recid, valueString FROM [e10_base_properties]';
		array_push ($q,' WHERE 1');
		array_push ($q,' AND [tableid] = %s', 'e10.persons.persons', ' AND [recid] = %i', $personNdx);
		array_push ($q,' AND [group] = %s', 'contacts', ' AND property = %s', 'email');

		$emails = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$emails[] = $r['valueString'];
		}

		$testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));
		if ($testNewPersons)
		{
			$q = [];
			array_push($q, 'SELECT contacts.* FROM [e10_persons_personsContacts] AS contacts');
			array_push($q, ' WHERE [contacts].[person] = %i', $personNdx);
			array_push($q, ' AND [contacts].[flagContact] = %i', 1);
			array_push($q, ' AND [contacts].[contactEmail] != %s', '');
			array_push($q, ' AND [contacts].[docState] = %i', 4000);
			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$emails[] = $r['contactEmail'];
			}
		}

		return $emails;
	}
}