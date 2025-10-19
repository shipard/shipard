<?php

namespace services\locAddr\libs;
use \Shipard\Base\Utility, \Shipard\Application\Response;

/**
 * class PapiAddrPlace
 */
class PapiAddrPlace extends Utility
{
	var $countryId = 0;
	var $addrPlaceId = 0;
	var $object = [];

	protected function getAddrPlace ()
	{
		$se = new \services\locAddr\libs\SearchAddrPlaceEngine($this->app());

		$q = [];
		$se->makeQueryBase($q);
		array_push ($q, ' AND [addrPlaces].[country] = %i', $this->countryId);
		array_push ($q, ' AND [addrPlaces].[addrPlaceId] = %i', $this->addrPlaceId);
    array_push ($q, ' ORDER BY ndx');
		array_push ($q, ' LIMIT 1');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = $r->toArray();
			$se->clearAddrPlaceRec($item);
      $this->object['addrPlace'] = $item;
			break;
    }

    $this->object['error'] = 0;
	}

	public function init ()
	{
		$this->countryId = intval($this->app->requestPath(2));
		$this->addrPlaceId = intval($this->app->requestPath(3));
	}

	public function run ()
	{
		$this->getAddrPlace();

		$response = new Response ($this->app);
		$response->add ('objectType', 'addrPlace');
		$response->setMimeType('application/json');
		$response->add ('object', $this->object);
		return $response;
	}
}
