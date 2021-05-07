<?php


namespace e10doc\core;

use \lib\persons\PersonsLastUse;


/**
 * Class PersonLastUseDocs
 * @package e10doc\core
 */
class PersonLastUseDocs extends PersonsLastUse
{
	var $docsTypes = [];

	protected function doItDocs ($roleId)
	{
		$this->lastUseRoleId = $roleId;

		$q[] = 'SELECT [person], MIN(dateAccounting) AS firstUse, MAX(dateAccounting) AS lastUse FROM [e10doc_core_heads]';
		array_push ($q, ' WHERE docStateMain != %i', 4);

		array_push ($q, ' AND (');
		array_push ($q, ' docType IN %in', $this->docsTypes);

		if ($this->lastUseTypeId === 'e10doc-docs-buy')
		{
			array_push($q, ' OR (docType = %s', 'cash', ' AND [cashBoxDir] = %i', 2,
				' AND EXISTS (SELECT document FROM e10doc_core_rows WHERE e10doc_core_heads.ndx = document AND operation IN %in)', [1010102, 1010199, 1099998],
				')');
		}
		else
		if ($this->lastUseTypeId === 'e10doc-docs-sale')
		{
			array_push($q, ' OR (docType = %s', 'cash', ' AND [cashBoxDir] = %i', 1,
				' AND EXISTS (SELECT document FROM e10doc_core_rows WHERE e10doc_core_heads.ndx = document AND operation IN %in)', [1010001, 1010002, 1010099],
				')');
		}

		array_push ($q, ')');

		array_push ($q, ' GROUP BY [person]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!$r['person'])
				continue;
			$this->setLastUse($r['person'], $r['firstUse'], $r['lastUse']);
		}
	}
}
