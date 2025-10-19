<?php

namespace services\locAddr\libs;
use \Shipard\Base\Utility, \Shipard\Application\Response;
use \shipard\Utils\Json;

/**
 * Class PapiAddrPlaces
 */
class PapiAddrPlaces extends Utility
{
	var $searchText = '';
	var $object = [];

	protected function getAddrPlaces ()
	{
    $this->object['search']['text'] = $this->searchText;

		$se = new \services\locAddr\libs\SearchAddrPlaceEngine($this->app());

		$q = [];
		$se->makeQueryBase($q);

		// -- fulltext
		if ($this->searchText != '')
		{
			$se->setText($this->searchText);
			if (count($se->qryStreets) || count($se->qryNumbers))
			{
				array_push ($q, ' AND (1 ');

				if (count($se->qryStreets) || count($se->qryCityParts))
				{
					$operator = ' OR ';

					array_push ($q, ' AND ( ');
					if ($operator === ' OR ')
						array_push ($q, '0');
					else
						array_push ($q, '1');

					if (count($se->qryStreets))
						array_push ($q, $operator.'[addrPlaces].[street] IN %in', $se->qryStreets);
					if (count($se->qryCityParts))
						array_push ($q, $operator.'[addrPlaces].[cityPart] IN %in', $se->qryCityParts);
					array_push ($q, ')');
				}

				if (count($se->qryNumbers))
					array_push ($q, ' AND [addrPlaces].[houseNr1] IN %in', $se->qryNumbers);
				array_push ($q, ')');
			}
    }

    array_push ($q, ' ORDER BY ndx');
		array_push ($q, ' LIMIT 100');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = $r->toArray();
			$se->clearAddrPlaceRec($item);
      $this->object['addrPlaces'][] = $item;
    }

    $this->object['error'] = 0;
	}

	public function init ()
	{
    $this->searchText = $this->app->testGetParam('q');
	}

	public function run ()
	{
    $this->searchText = $this->app->testGetParam('q');
		$this->getAddrPlaces();

		$response = new Response ($this->app);
		$response->add ('objectType', 'addrPlaces');
		$response->setMimeType('application/json');
		$response->add ('object', $this->object);
		return $response;
	}
}
