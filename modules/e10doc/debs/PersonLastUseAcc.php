<?php


namespace e10doc\debs;

use \e10doc\core\PersonLastUseDocs;


/**
 * Class PersonLastUseAcc
 * @package e10doc\debs
 */
class PersonLastUseAcc extends PersonLastUseDocs
{
	protected function init()
	{
		$this->lastUseTypeId = 'e10doc-docs-acc';
	}

	protected function doItAccJournal ($roleId)
	{
		$this->lastUseRoleId = $roleId;

		$q[] = 'SELECT [person], MIN(dateAccounting) AS firstUse, MAX(dateAccounting) AS lastUse FROM [e10doc_debs_journal]';
		array_push ($q, ' GROUP BY [person]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!$r['person'])
				continue;
			$this->setLastUse($r['person'], $r['firstUse'], $r['lastUse']);
		}
	}

	protected function doIt ()
	{
		$this->doItAccJournal(1);
	}
}
