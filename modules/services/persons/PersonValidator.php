<?php

namespace services\persons;

use \E10\utils, E10\Utility, E10\Response;


/**
 * Class PersonValidator
 * @package services\persons
 */
class PersonValidator extends Utility
{
	var $object = [];
	var $country = '';
	var $register = '';
	var $id = '';
	var $registerKey = '';
	var $registerEngine = NULL;

	public function init ()
	{
	}

	protected function checkParams()
	{
		$this->country = $this->app->requestPath(2);
		$this->register = $this->app->requestPath(3);
		$this->id = $this->app->requestPath(4);

		$this->registerKey = $this->country.'-'.$this->register;

		$registerDef = $this->app()->cfgItem ('registeredClasses.services-person-registers.'.$this->registerKey, FALSE);
		if ($registerDef === FALSE)
		{
			$this->object['error'] = 'Register '.$this->registerKey.' not found.';
			return;
		}

		$this->registerEngine = $this->app()->createObject($registerDef['classId']);
	}

	protected function doIt()
	{
		if (!$this->registerEngine)
			return;

		$this->registerEngine->setId ($this->id);
		$this->registerEngine->run();

		if ($this->registerEngine->data)
		{
			$this->object['valid'] = $this->registerEngine->data['valid'];
			$this->object['data'] = $this->registerEngine->data;
		}
	}

	public function run ()
	{
		$this->object['valid'] = 0;

		$this->checkParams();
		$this->doIt();

		$response = new Response ($this->app);
		$response->add ('objectType', 'person');
		$response->add ('object', $this->object);
		return $response;
	}
}
