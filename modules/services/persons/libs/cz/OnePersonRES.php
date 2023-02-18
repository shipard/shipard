<?php

namespace services\persons\libs\cz;
use \services\persons\libs\OnePerson, \Shipard\Utils\Str, \Shipard\Utils\Utils;

class OnePersonRES extends OnePerson
{
	var $srcData;
	var $csvString;
	var $person;

	public function setData($csvString)
	{
		$this->csvString = $csvString;
	}

	public function parse()
	{
		$cols = str_getcsv($this->csvString, ',');

		$this->person = ['base' => []];
		$this->person['base']['oid'] = Str::upToLen($cols[0] ?? '', 20);

		//if ($this->person['base']['oid'] !== '00444286')
		//	return FALSE;

		$this->person['base']['fullName'] = Str::upToLen(trim($cols[11] ?? '-----'), 240);
		$this->person['base']['originalName'] = $this->person['base']['fullName'];
		$this->person['base']['validFrom'] = Utils::createDateTime($cols[2] ?? NULL);
		$this->person['base']['validTo'] = Utils::createDateTime($cols[3] ?? NULL);
		$this->person['base']['country'] = 60;

		$street = '';
		if (trim($cols[18]) !== '')
			$street = trim(trim($cols[18]) . ' '.trim($cols[20]));
		elseif (trim($cols[20]) !== '')
			$street = trim(trim($cols[16]) . ' '.trim($cols[20])); // blank street, use city

		$this->person['address']['type'] = 0;
		$this->person['address']['street'] = Str::upToLen($street, 250);
		$this->person['address']['city'] = Str::upToLen(trim($cols[16]), 90);
		$this->person['address']['zipcode'] = Str::upToLen(trim(str_replace(' ', '', $cols[15])), 20);
		$this->person['address']['country'] = 60;

		if ($cols[13] !== '')
		{
			$this->person['address']['natAddressGeoId'] = intval($cols[13]);
			if ($this->person['address']['natAddressGeoId'] < 10000 || $this->person['address']['natAddressGeoId'] === 999999999)
				$this->person['address']['natAddressGeoId'] = 0;
		}

		if ($this->person['address']['street'] === '' && $this->person['address']['zipcode'] === '')
		{
			$parts = explode(',', $cols[14]);
			//if (isset($parts[0]))
			//	$this->person['address']['city'] = Str::upToLen(trim($parts[0]), 90);
			if (isset($parts[1]))
				$this->person['address']['street'] = Str::upToLen(trim($parts[1]), 250);

			if (isset($parts[2]))
			{
				$zc = trim($parts[2]);
				if (str_starts_with($zc, 'PSČ'))
					$zc = Str::substr($zc, 3);
				$this->person['address']['zipcode'] = Str::upToLen(str_replace(' ', '', trim($zc)), 20);
			}
		}

		//print_r($this->person);
		return TRUE;
	}
}
