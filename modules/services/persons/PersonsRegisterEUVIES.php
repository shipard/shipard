<?php

namespace services\persons;

use e10\json;


/**
 * Class PersonsRegisterEUVIES
 * @package services\persons
 */
class PersonsRegisterEUVIES extends \services\persons\PersonsRegister
{
	var $id = '';
	var $vat;

	static $URL_VIES = "http://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl";
	private $soap = NULL;

	public function loadFromCache ()
	{
		$today = new \DateTime();
		$today->sub (new \DateInterval('P7D'));

		$exist = $this->db()->query (
				'SELECT * FROM [services_persons_persons] WHERE id = %s', $this->id,
				' AND [register] = %i', $this->registerId,
				' AND [updated] >= %d', $today)->fetch();
		if ($exist)
		{
			$this->result = json_decode($exist['result'], TRUE);
			$this->data = [
					'id' => $this->result['countryCode'].$this->result['vatNumber'],
					'valid' => ($this->result['valid']) ? 1 : 0,
					'fullName' => $this->result['name']
			];

			return TRUE;
		}

		return FALSE;
	}

	public function load ()
	{
		if ($this->loadFromCache())
			return;

		$this->vat = [
				'countryCode'		=> substr($this->id, 0, 2),
				'vatNumber'	=> substr($this->id, 2),
		];

		$this->soap = new \SoapClient(self::$URL_VIES, ['exceptions' => 0]);
		$result = $this->soap->checkVat( $this->vat);

		if (is_soap_fault($result))
		{
			if ($result->faultstring === 'INVALID_INPUT')
			{
				$this->data = [
						'id' => $this->result['countryCode'].$this->result['vatNumber'],
						'valid' => 0,
						'fullName' => 'ERROR: '.$result->faultstring
				];
				$this->result = json_decode(json_encode($result), TRUE);
				return;
			}
			return;
		}

		$this->result = json_decode(json_encode($result), TRUE);

		$this->data = [
			'id' => $this->result['countryCode'].$this->result['vatNumber'],
			'valid' => ($this->result['valid']) ? 1 : 0,
			'fullName' => $this->result['name']
		];

		if ($this->result['valid'])
			$this->saveData();

		usleep(150000);
	}

	public function run ()
	{
		$this->registerId = self::prEU_VIES;
		$this->load();
	}
}
