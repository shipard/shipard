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
      'p1' => 'Adresní místo',
			'_options' => ['colSpan' => ['p1' => 3], 'cellClasses' => ['p1' => 'h2 pull-left']],
    ];
    $t[] = $row;

		$addrPlaceButtons = $this->addrPlaceButtons($this->recData['addrPlaceId']);
    $row = [
      'p1' => 'RÚIAN',
      'v1' => $this->recData['addrPlaceId'],
      'v2' => $addrPlaceButtons,
    ];
    $t[] = $row;


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
      'v1' => $this->recData['wgs84lat'].' / '.$this->recData['wgs84lng'],
      'v2' => $mapBtns,
    ];
    $t[] = $row;

		$h = ['p1' => 'Vlastnost', 'v1' => 'V1', 'v2' => 'V2'];
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'header' => $h, 'table' => $t,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);
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
			'icon' => 'system/iconMapMarker',
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
