<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;


/**
 * class ViewStdAddressPlaces
 */
class ViewStdAddressPlaces extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsDetail;

		$this->setMainQueries();

		parent::init();
	}

	public function selectRows ()
	{
		$this->rowsPageSize = 500;
		$this->queryRows = [];
		$this->ok = 1;

    if ($this->rowsFirst > 0)
      return;

    $fts = $this->fullTextSearch ();
    if ($fts === '')
      return;

    $url = 'https://data.shipard.app/papi/la-addr-places/?';
    $url .= http_build_query(['q' => $fts]);

    $response = Utils::http_get($url);

		$responseContent = NULL;
		if (isset($response['content']))
			$responseContent = json_decode($response['content'], TRUE);

    if (isset($responseContent['success']) && $responseContent['success'])
    {
      foreach ($responseContent['object']['addrPlaces'] as $r)
      {
        $this->queryRows[] = $r;
      }
    }
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['addrPlaceId'];
    $listItem ['data-cc']['stdAddrPlace'] = strval($item['addrPlaceId']);
    $listItem ['data-cc']['stdAddrPlaceData'] = json_encode($item);

		$listItem ['t1'] = [];
		if ($item['streetFullName'])
			$listItem ['t1'][] = ['text' => $item['streetFullName'], 'class' => 'label label-default'];

		$houseNr = ['text' => $item['houseNr'], 'class' => 'label label-default'];
		if ($item['houseNr1Type'])
			$houseNr['prefix'] = 'č.ev.';
		elseif ($item['houseNr1Type'] === 0 && !$item['street'] /*&& !$item['cityPart']*/)
			$houseNr['prefix'] = 'č.p.';

		$listItem ['t1'][] = $houseNr;

		$listItem ['i1'] = ['text' => '#'.Utils::nf($item['addrPlaceId']), 'class' => 'id'];

		$listItem ['t2'] = [];

		if ($item['cityPart2FullName'])
			$listItem ['t2'][] = ['text' => $item['cityPart2FullName'], 'class' => 'label label-warning'];

		$city = ['text' => $item['cityFullName'], 'class' => 'label label-success'];
		if ($item['zipCodeIdName'])
			$city['suffix'] = $item['zipCodeIdName'];

		if ($item['cityPartFullName'] && $item['cityPartFullName'] != $item['cityFullName'])
			$city['text'] .= ' - '.$item['cityPartFullName'];

		$listItem ['t2'][] = $city;

		if ($item['laUnitOwner2FullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner2FullName'], 'class' => 'label label-info'];
		if ($item['laUnitOwner1FullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner1FullName'], 'class' => 'label label-primary'];
		if ($item['laUnitOwner0FullName'] && $item['laUnitOwner0FullName'] != $item['cityFullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner0FullName'], 'class' => 'label label-default'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function createToolbar ()
	{
		return [];
	}
}
