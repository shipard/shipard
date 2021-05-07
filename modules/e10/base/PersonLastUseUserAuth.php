<?php


namespace e10\base;

use \lib\persons\PersonsLastUse;


/**
 * Class PersonLastUseUserAuth
 * @package e10\base
 */
class PersonLastUseUserAuth extends PersonsLastUse
{
	protected function init()
	{
		$this->lastUseTypeId = 'e10-base-userAuth';
	}

	protected function doIt ()
	{
		$q[] = 'SELECT [user], MIN(created) AS firstUse, MAX(created) AS lastUse FROM e10_base_authLog';
		array_push ($q, ' WHERE eventType IN %in', [1, 3]);
		array_push ($q, ' GROUP BY [user]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->setLastUse($r['user'], $r['firstUse'], $r['lastUse']);
		}
	}
}
