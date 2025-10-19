<?php

namespace services\locAddr\libs;
use \Shipard\Base\Utility, \Shipard\Application\Response;
use \shipard\Utils\Json;

/**
 * Class PapiAddrPlaces
 */
class PapiAddrPlacesSuggestions extends Utility
{
	var $searchText = '';
	var $searchStreet = '';
	var $searchCity = '';
	var $searchZipCode = '';

	var $object = [];

	protected function getAddrPlaces ()
	{
		$this->getAddrPlaces1();
		if (isset($this->object['addrPlaces']) && count($this->object['addrPlaces']))
			return;
		$this->getAddrPlaces2();
		if (isset($this->object['addrPlaces']) && count($this->object['addrPlaces']))
			return;
		$this->getAddrPlaces2(1);
	}

	protected function getAddrPlaces1 ()
	{
		if ($this->searchText!== '')
    	$this->object['search']['text'] = $this->searchText;

		$this->object['search']['usedPhase'] = 1;
    $this->object['search']['street'] = $this->searchStreet;
    $this->object['search']['city'] = $this->searchCity;
    $this->object['search']['zipCode'] = $this->searchZipCode;

		$se = new \services\locAddr\libs\SearchAddrPlaceEngine($this->app());

		$q = [];
		$se->makeQueryBase($q);

		if ($this->searchStreet !== '')
			$se->setStreetName($this->searchStreet);
		if ($this->searchCity !== '')
			$se->setCityName($this->searchCity);
		if ($this->searchZipCode !== '')
			$se->setZipCode($this->searchZipCode);

    $this->object['search']['streets'] = $se->qryStreets;
    $this->object['search']['cities'] = $se->qryCities;
    $this->object['search']['citiesParts'] = $se->qryCityParts;
		$this->object['search']['hrNumbers'] = $se->qryNumbers;
		$this->object['search']['zipCodes'] = $se->qryZipCodes;

		if (count($se->qryStreets))
			array_push ($q, 'AND [addrPlaces].[street] IN %in', $se->qryStreets);
		if (count($se->qryNumbers))
		{
			array_push ($q, ' AND [addrPlaces].[houseNr1] IN %in', $se->qryNumbers);
		}

		if (count($se->qryCities) && count($se->qryCityParts))
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [addrPlaces].[city] IN %in', $se->qryCities);
			array_push ($q, ' OR [addrPlaces].[cityPart] IN %in', $se->qryCityParts);
			array_push ($q, ')');
		}
		elseif (count($se->qryCities))
			array_push ($q, ' AND [addrPlaces].[city] IN %in', $se->qryCities);
		elseif (count($se->qryCityParts))
			array_push ($q, ' AND [addrPlaces].[cityPart] IN %in', $se->qryCityParts);

		if (count($se->qryZipCodes))
			array_push ($q, ' AND [addrPlaces].[zipCode] IN %in', $se->qryZipCodes);

    array_push ($q, ' ORDER BY ndx');
		array_push ($q, ' LIMIT 100');

    $rows = $this->db()->query($q);
		$this->object['search']['sql1'] = \Dibi::$sql;
    foreach ($rows as $r)
    {
      $item = $r->toArray();
      unset($item['ndx']);
			unset($item['laUnit10']);
			unset($item['laUnit11']);
			unset($item['zipCode']);
      Json::polish($item);
      $this->object['addrPlaces'][] = $item;
    }

    $this->object['error'] = 0;
	}

	protected function getAddrPlaces2 ($tryLevel = 0)
	{
		$this->searchText = $this->searchStreet;

		$this->object['search']['usedPhase'] = 2;
    $this->object['search']['text'] = $this->searchText;

		$q = [];
		array_push ($q, ' SELECT [addrPlaces].*,');
		array_push ($q, ' [cities].[fullName] AS [cityFullName],');
		array_push ($q, ' [zipCodes].[idName] AS [zipCodeIdName],');
		array_push ($q, ' [citiesParts].[fullName] AS [cityPartFullName],');
		array_push ($q, ' [citiesParts2].[fullName] AS [cityPart2FullName],');
		array_push ($q, ' [streets].[fullName] AS [streetFullName],');
    array_push ($q, ' [laUnits2].[laUnitId] AS [admUnit2Id], [laUnits2].[fullName] AS [admUnit2FullName],');
    array_push ($q, ' [laUnits1].[laUnitId] AS [admUnit1Id], [laUnits1].[fullName] AS [admUnit1FullName],');
    array_push ($q, ' [laUnits0].[laUnitId] AS [admUnit0Id], [laUnits0].[fullName] AS [admUnit0FullName],');
    array_push ($q, ' [laUnits11].[laUnitId] AS [admUnit11Id], [laUnits11].[fullName] AS [admUnit11FullName],');
    array_push ($q, ' [laUnits10].[laUnitId] AS [admUnit10Id], [laUnits10].[fullName] AS [admUnit10FullName]');
		array_push ($q, ' FROM [services_locAddr_addrPlaces] AS [addrPlaces]');
		array_push ($q, ' LEFT JOIN [services_locAddr_cities] AS [cities] ON addrPlaces.city = cities.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_zipCodes] AS [zipCodes] ON addrPlaces.zipCode = zipCodes.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_citiesParts] AS [citiesParts] ON addrPlaces.cityPart = citiesParts.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_citiesParts] AS [citiesParts2] ON addrPlaces.cityPart2 = citiesParts2.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_streets] AS [streets] ON addrPlaces.street = streets.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits2] ON cities.laUnit2 = laUnits2.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits1] ON cities.laUnit1 = laUnits1.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits0] ON cities.laUnit0 = laUnits0.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits11] ON cities.laUnit11 = laUnits11.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits10] ON cities.laUnit10 = laUnits10.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($this->searchText != '')
		{
			$se = new \services\locAddr\libs\SearchAddrPlaceEngine($this->app());
			$se->setText($this->searchText);
			if ($tryLevel === 0 && $this->searchZipCode !== '')
				$se->setZipCode($this->searchZipCode);

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

				if (count($se->qryZipCodes))
					array_push ($q, ' AND [addrPlaces].[zipCode] IN %in', $se->qryZipCodes);

				array_push ($q, ')');
			}
    }

    array_push ($q, ' ORDER BY ndx');
		array_push ($q, ' LIMIT 100');

    $rows = $this->db()->query($q);
		$this->object['search']['sql2'] = \Dibi::$sql;
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
		$this->searchStreet = $this->app->testGetParam('street');
		$this->searchCity = $this->app->testGetParam('city');
		$this->searchZipCode = $this->app->testGetParam('zipCode');
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
