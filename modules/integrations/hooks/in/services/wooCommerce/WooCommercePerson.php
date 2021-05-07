<?php

namespace integrations\hooks\in\services\wooCommerce;
use e10\utils;


/**
 * Class WooCommercePerson
 * @package integrations\hooks\in\services\wooCommerce
 */
class WooCommercePerson extends \integrations\hooks\in\services\DocHookPerson
{
	var $srcData = NULL;

	public function run()
	{
		$this->srcData = $this->hook->inPayload['data'];
		$this->dstDoc['rec'] = [];

		$setDocStates = FALSE;
		if ($this->hook->inParams['headers']['x-wc-webhook-topic'] === 'customer.created')
			$setDocStates = TRUE;

		$personId = 'E'.$this->hook->inRecData['hook'].$this->srcData['id'];

		$this->createPerson($this->srcData['billing'], $personId, $setDocStates);
		$this->saveDocument();
	}

	public function createPerson($personData, $srcPersonId, $setDocStates, $detectFromEmail = TRUE)
	{
		$this->srcData = $this->hook->inPayload['data'];
		$this->dstDoc['rec'] = [];

		if ($setDocStates)
		{
			$this->dstDoc['rec']['docState'] = 4000;
			$this->dstDoc['rec']['docStateMain'] = 2;
		}

		$personId = $srcPersonId;
		$this->dstDoc['rec']['id'] = $personId;

		if ($detectFromEmail)
			$this->detectExistedEmail ($personData['email'],$this->dstDoc['rec']);

		$company = intval($personData['company'] !== '');

		$this->dstDoc['rec']['company'] = $company;
		if ($company)
		{
			$this->dstDoc['rec']['fullName'] = $personData['company'];
		}
		else
		{
			$this->dstDoc['rec']['firstName'] = $personData['first_name'];
			$this->dstDoc['rec']['lastName'] = $personData['last_name'];
		}

		// -- properties
		$properties = [];

		if ($personData['email'] != '')
			$properties[] = ['value' => $personData['email'], 'group' => 'contacts', 'property' => 'email'];
		if ($personData['phone'] != '')
			$properties[] = ['value' => $personData['phone'], 'group' => 'contacts', 'property' => 'phone'];

		if (count($properties))
			$this->dstDoc['lists']['properties'] = $properties;

		// -- address
		$addresses = [];
		if ($personData['city'] !== '' || $personData['address_1'] !== '' || $personData['address_2'] !== '')
		{
			$a = ['city' => $personData['city'], 'zipcode' => $personData['postcode']];

			if ($personData['address_1'] !== '' && $personData['address_2'] !== '')
			{
				$a['specification'] = $personData['address_1'];
				$a['street'] = $personData['address_2'];
			}
			elseif ($personData['address_1'] !== '')
				$a['street'] = $personData['address_1'];
			elseif ($personData['address_2'] !== '')
				$a['street'] = $personData['address_2'];

			$a['country'] = strtolower($personData['country']);

			if ($personData['state'] !== '')
				$a['zipcode'] = $personData['state'].' '.$a['zipcode'];

			$addresses[] = $a;
		}

		if (count($addresses))
			$this->dstDoc['lists']['address'] = $addresses;
	}
}

