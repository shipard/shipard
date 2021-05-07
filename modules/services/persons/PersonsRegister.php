<?php

namespace services\persons;

use \E10\utils, E10\Utility, E10\Response, \e10\json;


/**
 * Class PersonsRegisterEUVIES
 * @package services\persons
 */
class PersonsRegister extends Utility
{
	CONST prEU_VIES = 1, prCZ_ARES = 2;

	var $registerId = 0;

	var $id = '';
	public $result = NULL;
	public $data = NULL;

	public function setId ($id)
	{
		$this->id = $id;
	}

	protected function saveData ()
	{
		$exist = $this->db()->query ('SELECT * FROM [services_persons_persons] WHERE id = %s', $this->id, ' AND [register] = %i', $this->registerId)->fetch();
		if ($exist)
		{
			$item = [
					'fullName' => $this->data['fullName'], 'valid' => 1,
					'result' => json::lint($this->result),
					'updated' => new \DateTime()
			];
			$this->db()->query ('UPDATE [services_persons_persons] SET ', $item, ' WHERE ndx = %i', $exist['ndx']);
		}
		else
		{
			$item = [
					'id' => $this->id, 'register' => $this->registerId,
					'fullName' => $this->data['fullName'], 'valid' => 1,
					'result' => json::lint($this->result),
					'created' => new \DateTime(), 'updated' => new \DateTime()
			];

			$this->db()->query ('INSERT INTO [services_persons_persons] ', $item);
		}
	}
}
