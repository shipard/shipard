<?php

namespace services\subjects;

use \e10\utils, \e10\json, \e10\Utility, \e10\Response;


/**
 * Class SourcesCfgFeed
 * @package services\subjects
 *
 * https://services.shipard.com/feed/sources-cfg
 */
class SourcesCfgFeed extends Utility
{
	var $object = [];

	public function init()
	{
	}

	public function createCfg()
	{
		$this->object['kinds'] = $this->app()->cfgItem('services.subjects.kinds');
		$this->object['sizes'] = $this->app()->cfgItem('services.subjects.sizes');
		$this->object['activities'] = $this->app()->cfgItem('services.subjects.activities');
		$this->object['commodities'] = $this->app()->cfgItem('services.subjects.commodities');
		$this->object['nomenc']['nuts-3'] = $this->app()->cfgItem('nomenc.cz-nuts-3');
		$this->object['nomenc']['nuts-4'] = $this->app()->cfgItem('nomenc.cz-nuts-4');
	}

	public function run ()
	{
		$this->createCfg();

		$response = new Response ($this->app);
		$data = json::lint ($this->object);
		$response->setRawData($data);
		return $response;
	}
}
