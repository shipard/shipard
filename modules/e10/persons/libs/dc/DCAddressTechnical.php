<?php

namespace e10\persons\libs\dc;

use e10\utils, e10\json;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\World;


/**
 * Class DCAddressTechnical
 */
class DCAddressTechnical extends \Shipard\Base\DocumentCard
{
	var $showPersonDocuments = TRUE;
	var $inForm = FALSE;

	var $info = [];

	public function createContent ()
	{
		$this->loadData();
		$this->createContentBody();
		//$this->createHeader();
	}

	function loadData()
	{
	}

  protected function addAddress()
  {
    if (!$this->recData['flagStandardized'])
      $this->addAddressOld();
    else
      $this->addAddrPlace();
  }

  protected function addAddressOld()
  {
    $t = [];

    $row = [
      'p1' => 'Nestandardizovaná adresa', 'v1' => '', 'v2' => '',
			'_options' => ['colSpan' => ['p1' => 3], 'cellClasses' => ['p1' => 'h2'], 'cellCss' => ['p1' => 'text-align: left;']],
    ];
    $t[] = $row;

    $row = [
      'p1' => 'Ulice',
      'v1' => $this->recData['adrStreet'],
      'v2' => '',
    ];
    $t[] = $row;

    $row = [
      'p1' => 'Obec',
      'v1' => $this->recData['adrCity'],
      'v2' => '',
    ];
    $t[] = $row;

    $row = [
      'p1' => 'PSČ',
      'v1' => $this->recData['adrZipCode'],
      'v2' => '',
    ];
    $t[] = $row;

    $country = World::country($this->app(), $this->recData['adrCountry']);
    $countryText = ($country) ? $country['f'].' '.$country['t'] : '!!! nazadaný stát !!!';

    $row = [
      'p1' => 'Země',
      'v1' => $countryText,
      'v2' => '',
      '_options' => ['cellClasses' => ['p1' => 'width10']],
    ];
    $t[] = $row;

    $h = ['p1' => 'Vlastnost', 'v1' => 'V1', 'v2' => 'V2'];
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table A1', 'header' => $h, 'table' => $t,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);

    $this->addOldAddressSuggestions();
  }

	protected function addAddrPlace()
	{
    $t = [];

    $row = [
      'p1' => 'Adresní místo',
			'_options' => ['colSpan' => ['p1' => 3], 'cellClasses' => ['p1' => 'h2 pull-left']],
    ];
    $t[] = $row;

		$addrPlaceButtons = $this->addrPlaceButtons($this->recData['natAddressGeoId']);
    $row = [
      'p1' => 'RÚIAN',
      'v1' => $this->recData['natAddressGeoId'],
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

    /*
    $row = [
      'p1' => 'S-JTSK',
      'v1' => 'Y: '.$this->recData['natGeoCoordY'].', '.'X: '.$this->recData['natGeoCoordX'],
      'v2' => '',
    ];
    $t[] = $row;
    */

		$mapBtns = $this->mapButtonsWgs84($this->recData['adrLocLat'], $this->recData['adrLocLon']);
    $row = [
      'p1' => 'GPS',
      'v1' => [['text' => $this->recData['adrLocLat'].' / '.$this->recData['adrLocLon'], 'class' => 'block']],
    ];
		$row['v1'] = array_merge($row['v1'], $mapBtns);
		$row['_options'] = ['colSpan' => ['v1' => 2]];
    $t[] = $row;

		$h = ['p1' => 'Vlastnost', 'v1' => 'V1', 'v2' => 'V2'];


		$content = [
			'pane' => 'e10-pane e10-pane-table A2', 'header' => $h, 'table' => $t,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		];

		if ($this->inForm)
			unset($content['pane']);

		$this->addContent ('body', $content);
	}

  public function addrPlaceButtons($addrPlaceId)
	{
		$mapBtns = [];
		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/adresnimista/'.$addrPlaceId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail adresního místa '.$addrPlaceId,
			'icon' => 'system/iconInfo', 'class' => 'ml1',
		];

		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/AD/'.$addrPlaceId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa místa '.$addrPlaceId,
			'icon' => 'system/iconMapMarker', 'class' => 'ml1',
		];

		return $mapBtns;
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
			'icon' => 'system/iconMapMarker', 'class' => 'ml1',
		];

		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://www.openstreetmap.cz/?mlat='.$x.'&mlon='.$y.'&zoom=18',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'osm.cz', 'title' => 'GPS: '.$x.', '.$y,
			'icon' => 'system/iconMapMarker', 'class' => 'ml1',
		];

		$mapBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://mapy.cz/fnc/v1/showmap?center='.$y.','.$x.'&zoom=17'.'&marker=true',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'mapy.cz', 'title' => 'GPS: '.$x.', '.$y,
			'icon' => 'system/iconMapMarker', 'class' => 'ml1',
		];

		return $mapBtns;
	}

	function addAddrPlace_Street(&$t)
	{
		$streetId = $this->recData['saStreetId'];

		$actionBtns = [];

		if ($streetId)
		{
			/*
			$actionBtns[] = [
				'type' => 'action', 'action' => 'editform',
				'data-table' => 'services.locAddr.streets', 'data-pk' => $this->recData['street'],
				'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
				'text' => '#'.$streetId, 'title' => 'Otevřít ulici '.$streetId,
				'icon' => 'system/actionOpen',
			];
			*/

			$actionBtns[] = [
				'type' => 'action', 'action' => 'open-popup',
				'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/ulice/'.$streetId,
				'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
				'text' => 'Detail', 'title' => 'Detail ulice '.$streetId,
				'icon' => 'system/iconInfo', 'class' => 'ml1',
			];

			$actionBtns[] = [
				'type' => 'action', 'action' => 'open-popup',
				'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/UL/'.$streetId.'/',
				'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
				'text' => 'Katastr', 'title' => 'Katastrální mapa ulice '.$streetId,
				'icon' => 'system/iconMapMarker', 'class' => 'ml1',
			];
		}

		$row = [
			'p1' => 'Ulice',
			'v1' => [
				['text' => $this->recData['saStreetName'], 'class' => ''],
				['text' => $this->recData['saHouseNr'], 'class' => 'label label-default'],
			],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

  function addAddrPlace_CityPart(&$t)
	{
		$cityPartId = $this->recData['saCityPartId'];
    if (!$cityPartId)
      return;

		$actionBtns = [];

    /*
		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'services.locAddr.citiesParts', 'data-pk' => $this->recData['cityPart'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '#'.$cityPartId, 'title' => 'Otevřít část obce '.$cityPartId,
			'icon' => 'system/actionOpen',
		];
    */

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/castiobce/'.$cityPartId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail části obce '.$cityPartId,
			'icon' => 'system/iconInfo', 'class' => 'ml1',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/CO/'.$cityPartId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa části obce '.$cityPartId,
			'icon' => 'system/iconMapMarker', 'class' => 'ml1',
		];


		$row = [
			'p1' => 'Část obce',
			'v1' => $this->recData['saCityPartName'],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

	function addAddrPlace_CityPart2(&$t)
	{
		$cityPartId = $this->recData['saCityPart2Id'];
    if (!$cityPartId)
      return;

		$actionBtns = [];

    /*
		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'services.locAddr.citiesParts', 'data-pk' => $this->recData['cityPart2'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '#'.$cityPartId, 'title' => 'Otevřít městskou část '.$cityPartId,
			'icon' => 'system/actionOpen',
		];
    */

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mestskecasti/'.$cityPartId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail městské části '.$cityPartId,
			'icon' => 'system/iconInfo', 'class' => 'ml1',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/MC/'.$cityPartId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa městské části '.$cityPartId,
			'icon' => 'system/iconMapMarker', 'class' => 'ml1',
		];


		$row = [
			'p1' => 'Městská část',
			'v1' => $this->recData['saCityPart2Name'],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

	function addAddrPlace_City(&$t)
	{
		$cityId = $this->recData['saCityId'];

		$actionBtns = [];

    /*
		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'services.locAddr.cities', 'data-pk' => $this->recData['city'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '#'.$cityId, 'title' => 'Otevřít město '.$cityId,
			'icon' => 'system/actionOpen',
		];
    */

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/obce/'.$cityId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Detail', 'title' => 'Detail obce '.$cityId,
			'icon' => 'system/iconInfo', 'class' => 'ml1',
		];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://vdp.cuzk.cz/vdp/ruian/mapa/OB/'.$cityId.'/',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'Katastr', 'title' => 'Katastrální mapa obce '.$cityId,
			'icon' => 'system/iconMapMarker', 'class' => 'ml1',
		];


		$row = [
			'p1' => 'Obec',
			'v1' => $this->recData['saCityName'],
			'v2' => $actionBtns,
		];
		$t[] = $row;
	}

	function addAddrPlace_laUnit11(&$t)
	{
		$laUnitRecData = $this->app()->loadItem($this->recData['saAdmUnit11Ndx'], 'e10.world.admUnits');
		if (!$laUnitRecData)
			return;

		$laUnitId = $this->recData['saAdmUnit11Id'];

		$actionBtns = [];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'e10.world.admUnits', 'data-pk' => $this->recData['saAdmUnit11Ndx'],
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
		$laUnitRecData = $this->app()->loadItem($this->recData['saAdmUnit10Ndx'], 'e10.world.admUnits');
		if (!$laUnitRecData)
			return;
		$laUnitId = $this->recData['saAdmUnit10Id'];

		$actionBtns = [];

		$actionBtns[] = [
			'type' => 'action', 'action' => 'editform',
			'data-table' => 'e10.world.admUnits', 'data-pk' => $this->recData['saAdmUnit10Ndx'],
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

  protected function addOldAddressSuggestions()
  {
    $addrStandardizationEngine = new \e10\persons\libs\AddrStandardizationEngine($this->app());
    $addrStandardizationEngine->setAddressRecData($this->recData);
    $addrStandardizationEngine->loadSuggestions();

    $addrStandardizationEngine->addSugestionsContent($this);
  }

	public function createContentBody ()
	{
    $this->addAddress();

		if ($this->showPersonDocuments)
		{
			$this->addContent ('body', [
				'sumTable' => [
					'objectId' => 'e10doc.core.libs.SumTablePersonAnalysis',
					'queryParams' => ['person_ndx' => $this->recData['person']]
				]
			]);
		}
	}
}
