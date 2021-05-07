<?php

namespace lib\persons;
use e10\Utility;


/**
 * Class PersonsLastUse
 * @package lib\persons
 */
class PersonsLastUse extends Utility
{
	var $lastUseTypeId = '';
	var $lastUseRoleId = 0;

	protected function init()
	{
	}

	protected function doIt ()
	{
	}

	protected function setLastUse ($personNdx, $dateFrom, $dateTo)
	{
		$dateStrTo = $dateTo->format('Y-m-d');
		$dateStrFrom = $dateFrom->format('Y-m-d');
		$exist = $this->db()->query ('SELECT * FROM [e10_persons_personsLastUse] WHERE [lastUseType] = %s', $this->lastUseTypeId,
			' AND [person] = %i', $personNdx, ' AND [lastUseRole] = %i', $this->lastUseRoleId)->fetch();
		if ($exist)
		{
			$item = ['firstUseDate' => $dateStrFrom, 'lastUseDate' => $dateStrTo, 'updated' => new \DateTime()];
			$this->db()->query ('UPDATE [e10_persons_personsLastUse] SET ', $item, ' WHERE [ndx] = %i', $exist['ndx']);
		}
		else
		{
			$item = [
				'person' => $personNdx, 'lastUseType' => $this->lastUseTypeId,
				'lastUseRole' => $this->lastUseRoleId, 'firstUseDate' => $dateStrFrom, 'lastUseDate' => $dateStrTo, 'updated' => new \DateTime()
			];
			$this->db()->query ('INSERT INTO [e10_persons_personsLastUse] ', $item);
		}
	}

	public function run()
	{
		$this->init();
		$this->doIt();
	}
}
