<?php

namespace e10\persons;

use e10\str, e10\Utility, e10\json, e10\utils;


/**
 * Class PersonValidator
 * @package e10\persons
 */
class PersonValidator extends Utility
{
	CONST validNotCheck = 0, validOK = 1, validError = 2;
	static $servicesUrl = 'https://services.shipard.com/feed/validate-person/';
	//static $servicesUrl = 'https://sebik-ds.shipard.pro/services/feed/validate-person/';

	var $personRecData;
	var $personNames;
	var $msg;
	var $country;
	var $valid;
	var $validVat;
	var $validOid;
	var $taxPayer;
	var $address;
	var $checkVat;
	var $checkOid;
	var $knownVatIds;
	var $notes;

	var $qryPersonId = NULL;

	var $generalFailure = FALSE;

	var $onlineToolsDef;

	protected function clear()
	{
		$this->personNames = [];
		$this->msg = [];
		$this->country = '';
		$this->address = [];
		$this->checkVat = [];
		$this->checkOid = [];
		$this->valid = self::validOK;
		$this->validVat = self::validOK;
		$this->validOid = self::validOK;
		$this->knownVatIds = [];
		$this->notes = [];
		$this->taxPayer = 0;
	}

	protected function checkAresCounter($inc = FALSE)
	{
		$now = new \DateTime ();
		$hour = intval($now->format('H'));
		$aresMode = ($hour >= 8 && $hour <= 18) ? 'day' : 'night';
		$aresLimit = ($hour >= 8 && $hour <= 18) ? 500 : 4000;
		$aresCounterKey = 'ares-'.$now->format('Y-m-d').'-'.$aresMode;

		$aresRequests = utils::serverCounter($aresCounterKey, $inc);
		if ($aresRequests > $aresLimit)
			return FALSE;

		return TRUE;
	}

	protected function checkPerson($recData)
	{
		$this->clear();
		$this->personRecData = $recData;

		$this->loadPersonNames();
		$this->loadPersonAddress();
		$this->loadPersonOid();
		$this->loadPersonVat();

		$this->checkPersonOid();
		if ($this->generalFailure)
			return;

		$this->checkPersonVat();
		if ($this->generalFailure)
			return;

		if (!count($this->notes))
			$this->notes[] = 'OK';

		$this->save();
	}

	public function save()
	{
		$item = [
				'valid' => $this->valid, 'validVat' => $this->validVat, 'validOid' => $this->validOid, 'taxPayer' => $this->taxPayer,
				'msg' => json::lint($this->msg),
				'updated' => new \DateTime(), 'revalidate' => 0
		];

		$exist = $this->db()->query('SELECT * FROM [e10_persons_personsValidity] WHERE [person] = %i', $this->personRecData['ndx'])->fetch();
		if ($exist)
		{
			$this->db()->query ('UPDATE [e10_persons_personsValidity] SET ', $item, ' WHERE ndx = %i', $exist['ndx']);
		}
		else
		{
			$item['person'] = $this->personRecData['ndx'];
			$item['created'] = new \DateTime();

			$this->db()->query ('INSERT INTO [e10_persons_personsValidity] ', $item);
		}

		$logEvent = [
				'tableid' => 'e10.persons.persons', 'recid' => $this->personRecData['ndx'], 'eventType' => 3,
				'eventData' => $item['msg']
		];
		$logEvent['eventSubtitle'] = str::upToLen('Kontrola osoby: '.implode(', ', $this->notes), 130);
		$logEvent['eventResult'] = ($this->valid === self::validOK) ? 1 : 2;

		$this->app()->addLogEvent($logEvent);
	}

	protected function checkPersonOid ()
	{
		foreach ($this->checkOid as $oidId => $oidInfo)
		{
			if ($this->generalFailure)
				return;

			if ($this->country !== 'cz')
				continue;

			$registerUrlPart = 'cz/ares';
			$registerName = 'ARES';

			$this->checkAresCounter(TRUE);

			$url = self::$servicesUrl.$registerUrlPart.'/'.$oidId;
			$result = $this->httpGet($url);
			if (!$result)
			{
				$this->generalFailure = TRUE;
				return;
			}

			if (!$result['object']['valid'])
			{
				$this->valid = self::validError;
				$this->validOid = self::validError;
				$this->msg['oid'][$oidId] = ['valid' => 0, 'msg' => 'Neplatné IČ - není v registru '.$registerName];
				$this->notes[] = 'Neplatné IČ '.$oidId;
			}
			else
			{
				if (!$this->cmpName($result['object']['data']['fullName']))
				{
					$this->valid = self::validError;
					$this->validOid = self::validError;
					$this->msg['oid'][$oidId] = ['valid' => 0, 'msg' => 'IČ je platné, ale nesouhlasí název v registru '.$registerName.': ', 'registerName' => $result['object']['data']['fullName']];
					$this->notes[] = 'IČ '.$oidId.' je někoho jiného';
				}
				else
				{
					$this->msg['oid'][$oidId] = ['valid' => 1, 'msg' => 'IČ je v pořádku'];
					if ($result['object']['data']['vatid'] !== '')
						$this->knownVatIds[] = $result['object']['data']['vatid'];
				}
			}
		}
	}

	protected function checkPersonVat ()
	{
		foreach ($this->checkVat as $vatId => $vatInfo)
		{
			if ($this->generalFailure)
				return;

			$registerName = 'VIES';
			$url = self::$servicesUrl.'eu/vat/'.$vatId;
			$result = $this->httpGet($url);
			if (!$result)
			{
				$this->generalFailure = TRUE;
				return;
			}

			if (!$result['object']['valid'])
			{
				$this->valid = self::validError;
				$this->validVat = self::validError;
				$this->msg['vat'][$vatId] = ['valid' => 0, 'msg' => 'Neplatné DIČ - není v registru VIES'];
				$this->notes[] = 'Neplatné DIČ '.$vatId;
			}
			else
			{
				$this->taxPayer = 1;
				if (!$this->cmpName($result['object']['data']['fullName'])
						&& !in_array($vatId, $this->knownVatIds)
						&& $result['object']['data']['fullName'] !== 'Group registration - This VAT ID corresponds to a Group of Taxpayers'
						&& $result['object']['data']['fullName'] !== '---')
				{
					$this->valid = self::validError;
					$this->validVat = self::validError;
					$this->msg['vat'][$vatId] = ['valid' => 0, 'msg' => 'DIČ je platné, ale nesouhlasí název v registru '.$registerName.': ', 'registerName' => $result['object']['data']['fullName']];
					$this->notes[] = 'DIČ '.$vatId.' je někoho jiného';
				}
				else
				{
					$this->msg['vat'][$vatId] = ['valid' => 1, 'msg' => 'DIČ je v pořádku'];
				}
			}
		}
	}

	protected function cmpName ($testedName)
	{
		foreach ($this->personNames as $name)
		{
			if (str::cmpwws($name, $testedName, ['.', ',']) === 0)
				return TRUE;
		}

		return FALSE;
	}

	protected function loadPersonAddress ()
	{
		$q[] = 'SELECT * FROM [e10_persons_address] AS address';
		array_push ($q, ' WHERE [recid] = %i', $this->personRecData['ndx']);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['type'] === 1 && $r['country'] !== '')
				$this->country = $r['country'];
			else
			if ($this->country === '' && $r['country'] !== '')
				$this->country = $r['country'];

			$this->address[] = $r->toArray();
		}
	}

	protected function loadPersonNames()
	{
		$this->personNames[] = $this->personRecData['fullName'];

		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $this->personRecData['ndx']);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'officialName');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$this->personNames[] = $r['valueString'];
		}
	}

	protected function loadPersonOid ()
	{
		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $this->personRecData['ndx']);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'oid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$this->checkOid[$r['valueString']] = ['valid' => 0];
		}
	}

	protected function loadPersonVat ()
	{
		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $this->personRecData['ndx']);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'taxid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$this->checkVat[$r['valueString']] = ['valid' => 0];
		}
	}

	protected function doIt ($checkType)
	{
		$q[] = 'SELECT * FROM [e10_persons_persons] as persons ';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docState] = %i', 4000);

		if ($checkType === 'revalidate')
			array_push($q, ' AND EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person AND revalidate = 1)');
		elseif ($checkType === 'unchecked')
		{
			$maxOldDate = new \DateTime('1 year ago');
			array_push($q, ' AND NOT EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person)');
			array_push($q, ' AND EXISTS (SELECT person FROM e10doc_core_heads WHERE persons.ndx = person AND dateAccounting > %d', $maxOldDate,
					' AND docType IN %in', ['invno', 'invni', 'cash', 'cashreg'], ')');
		}
		elseif ($checkType === 'personId')
		{
			array_push($q, ' AND [id] = %s', $this->qryPersonId);
		}

		array_push($q, ' LIMIT 0, 5');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($this->generalFailure)
				return;

			$this->checkPerson($r);
		}
	}

	public function run()
	{
		if ($this->qryPersonId)
		{
			$this->doIt('personId');
			return;
		}

		$this->doIt('revalidate');

		if (!$this->checkAresCounter())
			return;

		$this->doIt('unchecked');
	}

	public function onlineTools ($personRecData)
	{
		$this->clear();
		$this->personRecData = $personRecData;

		$this->loadPersonAddress();
		$this->loadPersonOid();
		$this->loadPersonVat();

		$tools = [];
		$this->onlineToolsDef = $this->loadCfgFile(__SHPD_MODULES_ROOT__.'e10/persons/config/e10.persons.onlineTools.json');

		// -- OID
		foreach ($this->checkOid as $oidId => $oidInfo)
		{
			$this->onlineToolsCheck('oid', $oidId, $tools);
		}
		// -- VATID
		foreach ($this->checkVat as $vatId => $vatInfo)
		{
			$this->onlineToolsCheck('vatid', $vatId, $tools);
		}

		if (!count($tools))
			return FALSE;

		return $tools;
	}

	protected function onlineToolsCheck ($type, $value, &$tools)
	{
		foreach ($this->onlineToolsDef as $t)
		{
			if ($t['type'] !== $type)
				continue;

			$country = ($type === 'vatid') ? strtolower(substr($value, 0, 2)) : $this->country;
			if ($t['country'] !== $country)
				continue;

			$url = str_replace('@ID', $value, $t['weburl']);
			$tool = ['text' => $t['name'], 'url' => $url];

			$tools[] = $tool;
		}
	}
}
