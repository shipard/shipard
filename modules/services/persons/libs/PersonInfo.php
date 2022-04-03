<?php

namespace services\persons\libs;

use \Shipard\Utils\Utils, \Shipard\Utils\Json, \Shipard\Base\Utility, \Shipard\Application\Response;


/**
 * Class PersonInfo
 */
class PersonInfo extends Utility
{
	var $object = [];
	var $country = '';
	var $register = '';
	var $oid = '';
	var $vatId = '';
	var $registerKey = '';
	var $registerEngine = NULL;

	var $persons = [];

	public function init ()
	{
	}

	protected function checkParams()
	{
		$this->country = $this->app->testGetParam('country');
		if ($this->country === '')
			$this->country = 'cz';


	//	$this->register = $this->app->requestPath(3);
		$this->oid = $this->app->testGetParam('oid');
		$this->vatId = $this->app->testGetParam('vatId');

		$this->object['valid'] = 1;

/*
		$this->registerKey = $this->country.'-'.$this->register;

		$registerDef = $this->app()->cfgItem ('registeredClasses.services-person-registers.'.$this->registerKey, FALSE);
		if ($registerDef === FALSE)
		{
			$this->object['error'] = 'Register '.$this->registerKey.' not found.';
			return;
		}

		$this->registerEngine = $this->app()->createObject($registerDef['classId']);
		*/
	}

	protected function checkCorePerson()
	{
		if ($this->oid === '')
			return;

		$q = [];
		array_push ($q, 'SELECT * FROM [services_persons_persons]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [oid] = %s', $this->oid);

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$p = ['person' => $r->toArray(), 'address' => []];
			Json::polish($p['person']);
			// -- address
			$rowsAddr = $this->db()->query ('SELECT * FROM [services_persons_address] WHERE [person] = %i', $r['ndx']);
			foreach ($rowsAddr as $ra)
			{
				$raa = $ra->toArray();
				Json::polish($raa);
				$p['address'][] = $raa;
			}
			
			$this->persons[] = $p;
		}
	}

	protected function doIt()
	{
		$this->checkCorePerson();
		$this->TEST_VAT();

		/*
		https://wwwinfo.mfcr.cz/cgi-bin/ares/ares_es.cgi?jazyk=cz&obch_jm=&ico=73896110&cestina=cestina&obec=&k_fu=&maxpoc=200&ulice=&cis_or=&cis_po=&setrid=ZADNE&pr_for=&nace=&xml=0&filtr=1

		if (!$this->registerEngine)
			return;

		$this->registerEngine->setId ($this->id);
		$this->registerEngine->run();

		if ($this->registerEngine->data)
		{
			$this->object['valid'] = $this->registerEngine->data['valid'];
			$this->object['data'] = $this->registerEngine->data;
		}
		*/
	}

	public function TEST_VAT()
	{
		$vatId = 'CZ46343504';
		$client = new \SoapClient('http://adisrws.mfcr.cz/adistc/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP?wsdl');

		$response = $client->__soapCall('getStatusNespolehlivyPlatce', [0 => [$vatId]]);
		//print_r($response);

		$this->persons['VAT'] = $response;
	}

	public function run ()
	{
		$this->object['valid'] = 0;

		$this->checkParams();
		$this->doIt();

		$response = new Response ($this->app);
		$response->add ('objectType', 'person');
		$response->add ('object', $this->object);
		$response->add ('persons', $this->persons);
		return $response;
	}
}
