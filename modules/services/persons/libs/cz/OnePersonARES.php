<?php

namespace services\persons\libs\cz;
use \services\persons\libs\OnePerson, \Shipard\Utils\Str, \Shipard\Utils\Utils, \Shipard\Utils\World;

class OnePersonARES extends OnePerson
{
	var $xml;
	var $xmlString;
	var $srcData;

	var $person;

	public function setData($xmlString)
	{
		$this->xmlString = $xmlString;
	}

	public function parse()
	{
		$this->xml = @simplexml_load_string ($this->xmlString);
		if (!isset($this->xml) || !$this->xml)
		{
			echo "ERROR XML PARSE!\n";
			return;
		}

		$ns = $this->xml->getDocNamespaces(TRUE);
		$dataXml = $this->xml->children($ns['are']);
		$this->srcData = json_decode (json_encode($dataXml), TRUE);

		//print_r($this->srcData);


		$this->person = ['base' => []];

		$aresInfo = NULL;
		if (!isset($this->srcData['Odpoved']['Vypis_VREO']['Zakladni_udaje']))
		{
			if (isset($this->srcData['Odpoved']['Vypis_VREO']) && is_array($this->srcData['Odpoved']['Vypis_VREO']))
			{
				//$aresInfo = array_pop($this->srcData['Odpoved']['Vypis_VREO']);
				foreach ($this->srcData['Odpoved']['Vypis_VREO'] as $oneAI)
				{
					$aresInfo = $oneAI;
					break;
				}
			}
		}
		else
		{
			$aresInfo = $this->srcData['Odpoved']['Vypis_VREO'];
		}

		if (!$aresInfo)
		{
			return FALSE;
		}

		if (isset($aresInfo['Zakladni_udaje']['ICO']) && is_array($aresInfo['Zakladni_udaje']['ICO']))
			$this->person['base']['oid'] = Str::upToLen($aresInfo['Zakladni_udaje']['ICO'][0] ?? '', 20);
		else
			$this->person['base']['oid'] = Str::upToLen($aresInfo['Zakladni_udaje']['ICO'] ?? '', 20);

		if (isset($aresInfo['Zakladni_udaje']['ObchodniFirma']) && is_array($aresInfo['Zakladni_udaje']['ObchodniFirma']))
			$this->person['base']['fullName'] = Str::upToLen($aresInfo['Zakladni_udaje']['ObchodniFirma'][0], 240);
		else
			$this->person['base']['fullName'] = Str::upToLen($aresInfo['Zakladni_udaje']['ObchodniFirma'] ?? '-----', 240);

        $this->person['base']['originalName'] = $this->person['base']['fullName'];
		$this->person['base']['validFrom'] = Utils::createDateTime($aresInfo['Zakladni_udaje']['DatumZapisu']);
		$this->person['base']['validTo'] = Utils::createDateTime($aresInfo['Zakladni_udaje']['DatumVymazu'] ?? NULL);

		$aresCountry = intval($aresInfo['Zakladni_udaje']['Sidlo']['stat'] ?? 203);
		if ($aresCountry === 203)
			$this->person['base']['country'] = 60;
		else
		{
			$this->person['base']['country'] = 0;
		}

		if (isset($aresInfo['Zakladni_udaje']['Sidlo']))
		{
			$streetNumber = trim($aresInfo['Zakladni_udaje']['Sidlo']['cisloPop'] ?? $aresInfo['Zakladni_udaje']['Sidlo']['cisloTxt'] ?? '');
			$street = '';
			if (trim($aresInfo['Zakladni_udaje']['Sidlo']['ulice'] ?? '') !== '')
				$street = trim(trim($aresInfo['Zakladni_udaje']['Sidlo']['ulice'])) . ' '.$streetNumber;
			else
				$street = trim(trim($aresInfo['Zakladni_udaje']['Sidlo']['obec'] ?? '')) . ' '.$streetNumber; // blank street, use city

			$this->person['address']['type'] = 0;
			$this->person['address']['street'] = Str::upToLen(trim($street), 250);
			$this->person['address']['city'] = Str::upToLen(trim($aresInfo['Zakladni_udaje']['Sidlo']['obec'] ?? ''), 90);
			$this->person['address']['zipcode'] = Str::upToLen(str_replace(' ', '', trim($aresInfo['Zakladni_udaje']['Sidlo']['psc'] ?? '')), 20);
			$this->person['address']['country'] = 60;

			if (isset($aresInfo['Zakladni_udaje']['Sidlo']['ruianKod']))
			{
				$this->person['address']['natAddressGeoId'] = intval($aresInfo['Zakladni_udaje']['Sidlo']['ruianKod']);
				if ($this->person['address']['natAddressGeoId'] < 10000 || $this->person['address']['natAddressGeoId'] === 999999999)
					$this->person['address']['natAddressGeoId'] = 0;
			}
		}

		return TRUE;
		//print_r($this->person);
	}
}
