<?php

namespace integrations\hooks\in\services;
use e10\Utility;


/**
 * Class DocHookPerson
 * @package integrations\hooks\in\services
 */
class DocHookPerson extends \integrations\hooks\in\services\DocHookCore
{
	public function setHook($hook)
	{
		parent::setHook($hook);
		$this->setTable('e10.persons.persons');
	}

	protected function detectExistedEmail ($email, &$dstData)
	{
		$rows = $this->db()->query ('SELECT recid, valueString FROM [e10_base_properties] WHERE [group] = %s', 'contacts',
			' AND property = %s', 'email', ' AND valueString = %s', $email);

		$personId = '';
		$personNdx = 0;
		$existedPersons = [];

		forEach ($rows as $r)
		{
			$ndx = $r['recid'];

			$personRecData = $this->table->loadItem($ndx);
			if (!$personRecData || $personRecData['docState'] === 9800)
				continue;

			$personId = $personRecData['id'];
			$personNdx = $ndx;

			$existedPersons[$ndx] = $personRecData;
		}

		if (count($existedPersons) === 1)
		{
			$dstData['ndx'] = $personNdx;
			$dstData['id'] = $personId;
		}
	}
}
