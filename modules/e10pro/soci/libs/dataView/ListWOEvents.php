<?php

namespace e10pro\soci\libs\dataView;
use \lib\dataView\DataView, \Shipard\Utils\Utils, \Shipard\Utils\Json;
use \e10\base\libs\UtilsBase;

use function e10\sortByOneKey;

/**
 * class ListWOEvents
 */
class ListWOEvents extends DataView
{
	var $orderBy = '';

  protected function init()
	{
		parent::init();

		$this->checkRequestParamsList('docKinds', TRUE);
		$this->checkRequestParamsList('withLabels');
		$this->checkRequestParamsList('withoutLabels');
    $this->checkRequestParamsList('places', TRUE);
		$this->checkRequestParamsList('periods', TRUE);

		$this->orderBy = $this->requestParam('orderBy', 'beginOrder');
	}

	protected function loadData()
	{
		/** @var \e10\persons\TableAddress */
		$tableAddress = $this->app()->table('e10.persons.address');

		/** @var \e10\base\TablePlaces */
		$tablePlaces = $this->app()->table('e10.base.places');


		$q = [];
		array_push ($q, 'SELECT wo.*,');
		array_push ($q, ' places.fullName AS placeFullName, places.shortName AS placeShortName, places.id AS placeId');
		array_push ($q, ' FROM [e10mnf_core_workOrders] AS wo');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON wo.place = places.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND wo.docState IN %in', [1200, 8000]);

		if (isset($this->requestParams['withLabels']) && count($this->requestParams['withLabels']))
		{
			array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE wo.ndx = recid AND tableId = %s', 'e10mnf.core.workOrders',
				' AND [clsfItem] IN %in', $this->requestParams['withLabels'],
				')');
		}
		if (isset($this->requestParams['withoutLabels']) && count($this->requestParams['withoutLabels']))
		{
			array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE wo.ndx = recid AND tableId = %s', 'e10mnf.core.workOrders',
				' AND [clsfItem] IN %in', $this->requestParams['withoutLabels'],
				')');
		}

    if (isset($this->requestParams['docKinds']))
      array_push ($q, ' AND wo.docKind IN %in', $this->requestParams['docKinds']);

		if (isset($this->requestParams['places']))
      array_push ($q, ' AND wo.place IN %in', $this->requestParams['places']);

		if (isset($this->requestParams['periods']))
      array_push ($q, ' AND wo.usersPeriod IN %in', $this->requestParams['periods']);


		array_push ($q, ' ORDER BY wo.[title], wo.[ndx]');

		$t = [];
		$pks = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
        'ndx' => $r['ndx'],
				'title' => $r['title'],
				'wo' => $r->toArray(),
				'place' => [
					'fullName' => $r['placeFullName'],
					'shortName' => $r['placeShortName'],
					'id' => $r['placeId'],
				]
      ];


			$item['place']['address'] = $tableAddress->loadAddresses($tablePlaces, $r['place']);

			array_push ($q, ' places.fullName AS placeFullName, places.shortName AS placeShortName, places.id AS placeId');


      $vdsData = Json::decode($r['vdsData']);
      if ($vdsData)
      {
				$beginOrder = '';
				if (isset($vdsData['weekDay']))
					$beginOrder .= $vdsData['weekDay'];
				if (isset($vdsData['startTime']))
					$beginOrder .= $vdsData['startTime'];

				$item['beginOrder']	= $beginOrder;
        $item['data'] = $vdsData;

        if (isset($vdsData['publicEmail']) && $vdsData['publicEmail'] !== '')
          $item['email'] = $vdsData['publicEmail'];
      }

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		// -- capacity
		$qcp = [];
		array_push($qcp, 'SELECT entryTo, COUNT(*) AS cnt');
		array_push($qcp, ' FROM e10pro_soci_entries AS entries');
		array_push($qcp, ' WHERE entryTo IN %in', $pks);
		array_push($qcp, ' AND docState IN %in', [1000, 4000, 8000]);
		array_push($qcp, ' GROUP BY 1');
		$cpRows = $this->db()->query($qcp);
		foreach ($cpRows as $cpr)
		{
			$woNdx = $cpr['entryTo'];
			$capacity = intval($t[$woNdx]['data']['capacity'] ?? 0);
			$t[$woNdx]['cntEntries'] = $cpr['cnt'];
			$t[$woNdx]['eventCapacity'] = $capacity;
			if ($capacity && $cpr['cnt'] >= $capacity)
				$t[$woNdx]['overCapacity'] = 1;
		}

		// -- linkedPersons
		$linkedPersons = UtilsBase::linkedPersons ($this->app(), 'e10mnf.core.workOrders', $pks);
		foreach ($linkedPersons as $wkNdx => $lp)
		{
			if (isset($lp['e10mnf-workRecs-admins']))
				$t[$wkNdx]['lp']['admins'] = $lp['e10mnf-workRecs-admins'];
		}

		if ($this->orderBy === 'beginOrder')
			$t = sortByOneKey($t, 'beginOrder', TRUE);

		$this->data['events'] = array_values($t);
	}

	protected function renderDataAs($showAs)
	{
    return '';
	}
}

