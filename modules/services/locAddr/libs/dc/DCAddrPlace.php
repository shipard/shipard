<?php

namespace services\locAddr\libs\dc;


/**
 * class DCAddrPlace
 */
class DCAddrPlace extends \Shipard\Base\DocumentCard
{
	function addAddrPlace()
	{
    $t = [];

    $row = [
      'p1' => [['text' => 'Adresní místo', 'class' => '']],
			'_options' => ['colSpan' => ['p1' => 3], 'cellClasses' => ['p1' => 'h2'], 'cellCss' => ['p1' => 'text-align: left;']],
    ];

		if ($this->recData['addrPlaceCanceled'])
		{
			$row['p1'][] = ['text' => 'ZRUŠENO', 'class' => 'label label-danger ml1 pull-right', 'icon' => 'system/iconWarning'];
		}

    $t[] = $row;

		$addrPlaceButtons = $this->addrPlaceButtons($this->recData['addrPlaceId']);
    $row = [
      'p1' => 'RÚIAN',
      'v1' => $this->recData['addrPlaceId'],
      'v2' => $addrPlaceButtons,
    ];
    $t[] = $row;

		$this->addAddrPlace_Street($t);
		$this->addAddrPlace_CityPart($t);
		$this->addAddrPlace_CityPart2($t);
		$this->addAddrPlace_City($t);

		$this->addAddrPlace_laUnit11($t);
		$this->addAddrPlace_laUnit10($t);

		// -- map coordinates
    $row = [
      'p1' => 'Geografické souřadnice',
			'_options' => ['colSpan' => ['p1' => 3], 'cellClasses' => ['p1' => 'h2 pull-left']],
    ];
    $t[] = $row;

    $row = [
      'p1' => 'S-JTSK',
      'v1' => 'Y: '.$this->recData['natGeoCoordY'].', '.'X: '.$this->recData['natGeoCoordX'],
      'v2' => '',
    ];
    $t[] = $row;


		$mapBtns = $this->mapButtonsWgs84($this->recData['wgs84lat'], $this->recData['wgs84lng']);
    $row = [
      'p1' => 'WGS-84',
      'v1' => [['text' => $this->recData['wgs84lat'].' / '.$this->recData['wgs84lng'], 'class' => 'block']],
    ];
		$row['v1'] = array_merge($row['v1'], $mapBtns);
		$row['_options'] = ['colSpan' => ['v1' => 2]];
    $t[] = $row;

		$h = ['p1' => 'Vlastnost', 'v1' => 'V1', 'v2' => 'V2'];
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'header' => $h, 'table' => $t,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);
	}

	function addAddrPlace_Street(&$t)
	{
		$streetRecData = $this->app()->loadItem($this->recData['street'], 'services.locAddr.streets');
		if (!$streetRecData)
			return;
		$streetId = $streetRecData['streetId'];

		$actionBtns = [];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'services.locAddr.streets', 'data-pk' => $this->recData['street'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '#'.$streetId, 'title' => 'Otevřít ulici '.$streetId,
			'icon' => 'system/actionOpen',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/ulice/'.$streetId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail ulice '.$streetId,
			'icon' => 'system/iconInfo',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/UL/'.$streetId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa ulice '.$streetId,
			'icon' => 'system/iconMapMarker',
		];


		$row = [
			'p1' => 'Ulice',
			'v1' => [
				['text' => $streetRecData['fullName'], 'class' => ''],
				['text' => $this->recData['houseNr'], 'class' => 'label label-default'],
			],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

	function addAddrPlace_City(&$t)
	{
		$cityRecData = $this->app()->loadItem($this->recData['city'], 'services.locAddr.cities');
		if (!$cityRecData)
			return;
		$cityId = $cityRecData['cityId'];

		$actionBtns = [];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'services.locAddr.cities', 'data-pk' => $this->recData['city'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '#'.$cityId, 'title' => 'Otevřít město '.$cityId,
			'icon' => 'system/actionOpen',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/obce/'.$cityId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail obce '.$cityId,
			'icon' => 'system/iconInfo',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/OB/'.$cityId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa obce '.$cityId,
			'icon' => 'system/iconMapMarker',
		];


		$row = [
			'p1' => 'Obec',
			'v1' => $cityRecData['fullName'],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

	function addAddrPlace_CityPart(&$t)
	{
		$cityPartRecData = $this->app()->loadItem($this->recData['cityPart'], 'services.locAddr.citiesParts');
		if (!$cityPartRecData)
			return;
		$cityPartId = $cityPartRecData['cityPartId'];

		$actionBtns = [];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'services.locAddr.citiesParts', 'data-pk' => $this->recData['cityPart'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '#'.$cityPartId, 'title' => 'Otevřít část obce '.$cityPartId,
			'icon' => 'system/actionOpen',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/castiobce/'.$cityPartId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail části obce '.$cityPartId,
			'icon' => 'system/iconInfo',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/CO/'.$cityPartId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa části obce '.$cityPartId,
			'icon' => 'system/iconMapMarker',
		];


		$row = [
			'p1' => 'Část obce',
			'v1' => $cityPartRecData['fullName'],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

	function addAddrPlace_CityPart2(&$t)
	{
		$cityPartRecData = $this->app()->loadItem($this->recData['cityPart2'], 'services.locAddr.citiesParts');
		if (!$cityPartRecData)
			return;
		$cityPartId = $cityPartRecData['cityPartId'];

		$actionBtns = [];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'services.locAddr.citiesParts', 'data-pk' => $this->recData['cityPart2'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '#'.$cityPartId, 'title' => 'Otevřít městskou část '.$cityPartId,
			'icon' => 'system/actionOpen',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mestskecasti/'.$cityPartId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail městské části '.$cityPartId,
			'icon' => 'system/iconInfo',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/MC/'.$cityPartId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa městské části '.$cityPartId,
			'icon' => 'system/iconMapMarker',
		];


		$row = [
			'p1' => 'Městská část',
			'v1' => $cityPartRecData['fullName'],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

	function addAddrPlace_laUnit11(&$t)
	{
		$laUnitRecData = $this->app()->loadItem($this->recData['laUnit11'], 'services.locAddr.laUnits');
		if (!$laUnitRecData)
			return;
		$laUnitId = $laUnitRecData['laUnitId'];

		$actionBtns = [];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'services.locAddr.laUnits', 'data-pk' => $this->recData['laUnit11'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '#'.$laUnitId, 'title' => 'Otevřít ZUJ '.$laUnitId,
			'icon' => 'system/actionOpen',
		];

		/*
		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/castiobce/'.$laUnitId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail části obce '.$laUnitId,
			'icon' => 'system/iconInfo',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/CO/'.$cityPartId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa části obce '.$cityPartId,
			'icon' => 'system/iconMapMarker',
		];
		*/

		$row = [
			'p1' => 'ZUJ',
			'v1' => $laUnitRecData['fullName'],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

	function addAddrPlace_laUnit10(&$t)
	{
		$laUnitRecData = $this->app()->loadItem($this->recData['laUnit10'], 'services.locAddr.laUnits');
		if (!$laUnitRecData)
			return;
		$laUnitId = $laUnitRecData['laUnitId'];

		$actionBtns = [];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'services.locAddr.laUnits', 'data-pk' => $this->recData['laUnit10'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '#'.$laUnitId, 'title' => 'Otevřít ORP '.$laUnitId,
			'icon' => 'system/actionOpen',
		];

		/*
		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/orp/'.$laUnitId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail ORP '.$laUnitId,
			'icon' => 'system/iconInfo',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/OP/'.$laUnitId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa ORP '.$laUnitId,
			'icon' => 'system/iconMapMarker',
		];
		*/

		$row = [
			'p1' => 'ORP',
			'v1' => $laUnitRecData['fullName'],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

	public function mapButtonsWgs84($x, $y)
	{
		$mapBtns = [];
		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://maps.google.com/?q='.$x.','.$y,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'google', 'title' => 'GPS: '.$x.', '.$y,
			'icon' => 'system/iconMapMarker',
		];

		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://www.openstreetmap.org/?mlat='.$x.'&mlon='.$y.'&zoom=18',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'osm', 'title' => 'GPS: '.$x.', '.$y,
			'icon' => 'system/iconMapMarker',
		];

		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://www.openstreetmap.cz/?mlat='.$x.'&mlon='.$y.'&zoom=18',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'osm.cz', 'title' => 'GPS: '.$x.', '.$y,
			'icon' => 'system/iconMapMarker',
		];

		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://mapy.cz/fnc/v1/showmap?center='.$y.','.$x.'&zoom=17'.'&marker=true',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'mapy.cz', 'title' => 'GPS: '.$x.', '.$y,
			'icon' => 'system/iconMapMarker',
		];

		return $mapBtns;
	}

	public function addrPlaceButtons($addrPlaceId)
	{
		$mapBtns = [];
		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/adresnimista/'.$addrPlaceId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail adresního místa '.$addrPlaceId,
			'icon' => 'system/iconInfo',
		];

		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/AD/'.$addrPlaceId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa místa '.$addrPlaceId,
			'icon' => 'system/iconMapMarker',
		];

		return $mapBtns;
	}

	public function createContentBody ()
	{
		$this->addAddrPlace();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
