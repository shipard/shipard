<?php

namespace services\persons;

use e10\json, e10\utils;


/**
 * Class PersonsRegisterCZARES
 * @package services\persons
 */
class PersonsRegisterCZARES extends \services\persons\PersonsRegister
{
	var $id = '';

	static $URL_ARES = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/darv_bas.cgi?ico=';

	protected function checkAresCounter($inc = FALSE)
	{
		$now = new \DateTime ();
		$hour = intval($now->format('H'));
		$aresMode = ($hour >= 8 && $hour <= 18) ? 'day' : 'night';
		$aresLimit = ($hour >= 8 && $hour <= 18) ? 500 : 4000;
		$aresCounterKey = 'ares-all-'.$now->format('Y-m-d').'-'.$aresMode;

		$aresRequests = utils::serverCounter($aresCounterKey, $inc);
		if ($aresRequests > $aresLimit)
			return FALSE;

		return TRUE;
	}

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
			$this->data = $this->result;

			return TRUE;
		}

		return FALSE;
	}

	public function load ()
	{
		if ($this->loadFromCache())
			return;

		$this->checkAresCounter(TRUE);

		$file = @file_get_contents (self::$URL_ARES . $this->id);

		if ($file)
			$xml = @simplexml_load_string ($file);
		if (isset($xml) && $xml)
		{
			$ns = $xml->getDocNamespaces();
			$data = $xml->children($ns['are']);
			$el = $data->children($ns['D'])->VBAS;
			if (strval($el->ICO) == $this->id)
			{
				$this->data ['oid'] = strval ($el->ICO);
				$this->data ['vatid'] = strval ($el->DIC);
				$this->data ['fullName'] = strval ($el->OF);

				$street = strval ($el->AA->NU);
				if ($street == '')
					$street = strval ($el->AA->N);
				$this->data ['street'] = $street . ' ' . strval($el->AA->CD);
				if ($el->AA->CO != '')
					$this->data ['street'] .= '/' . $el->AA->CO;

				$this->data ['city']= strval ($el->AA->N);
				$this->data ['zipcode']= strval ($el->AA->PSC);
				$this->data ['lastName'] = $this->data ['fullName'];
				$this->data ['valid'] = 1;

				$this->result = $this->data;
			}
			else
			{
				$this->data = [
						'id' => $el->ICO,
						'valid' => 0,
						'fullName' => 'ERROR: not found'
				];

				return;
			}
		}
		else
		{
			$this->data = [
					'id' => $this->id,
					'valid' => 0,
					'fullName' => 'ERROR: ARES is not available'
			];
			$this->result = ['error' => 'ARES is not available'];
			return;
		}

		$this->saveData();

		usleep(150000);
	}

	public function run ()
	{
		$this->registerId = self::prCZ_ARES;
		$this->load();
	}
}
