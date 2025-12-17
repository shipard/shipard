<?php

namespace services\locAddr\libs\dc;


/**
 * class DCAdmUnit
 */
class DCAdmUnit extends \Shipard\Base\DocumentCard
{
	function addAdmUnit()
	{
		$levels = $this->table->columnInfoEnum('level');
		$levelName = $levels[$this->recData['level']];



    $t = [];

    $row = [
      'p1' => [['text' => $levelName, 'class' => '']],
			'_options' => ['colSpan' => ['p1' => 3], 'cellClasses' => ['p1' => 'h2'], 'cellCss' => ['p1' => 'text-align: left;']],
    ];


    $t[] = $row;

		$mapButtons = [];
    if ($this->recData['wgs84lat'] != 0.0 && $this->recData['wgs84lng'] != 0.0)
      $mapButtons = $this->mapButtonsWgs84($this->recData['wgs84lat'], $this->recData['wgs84lng']);
    $row = [
      'p1' => 'ID',
      'v1' => $this->recData['laUnitId'],
      'v2' => $mapButtons,
    ];
    $t[] = $row;


    $row = [
      'p1' => 'Název',
      'v1' => $this->recData['fullName'],
      'v2' => '',
    ];
    $t[] = $row;

    if ($this->recData['laUnitOwner10'])
      $this->addAdmUnit_Level10($t);

    if ($this->recData['municipalityPerson'] !== 0)
      $this->addAdmUnit_MinicipalityPerson($t);

    $h = ['p1' => 'Parametr', 'v1' => 'Hodnota', 'v2' => ''];
		// -- map coordinates
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'header' => $h, 'table' => $t,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);
	}

  function addAdmUnit_Level10(&$t)
	{ // ORP
    $admUnit10 = $this->table->app()->loadItem($this->recData['laUnitOwner10'], 'services.locAddr.laUnits');
    if ($admUnit10)
    {
      $row = [
        'p1' => 'ORP',
        'v1' => $admUnit10['fullName'],
        'v2' => '#'.$admUnit10['laUnitId'],
      ];
      $t[] = $row;
    }
  }

  function addAdmUnit_MinicipalityPerson(&$t)
	{
    $mp = $this->table->app()->loadItem($this->recData['municipalityPerson'], 'services.persons.persons');
    $row = [
      'p1' => 'IČ obce',
      'v1' => $this->recData['municipalityPersonOid'],
      'v2' => '',
    ];
    $t[] = $row;

    $row = [
      'p1' => 'Obec - osoba',
      'v1' => $mp['fullName'],
      'v2' => '',
    ];
    $t[] = $row;

    // -- address
    $q = [];
    array_push($q, 'SELECT addresses.*');
    array_push($q, ' FROM [services_persons_address] AS [addresses]');
    array_push($q, ' WHERE addresses.person = %i', $this->recData['municipalityPerson']);
    array_push($q, ' AND addresses.[type] = %i', 0);
    array_push($q, ' ORDER BY ndx DESC');
    array_push($q, ' LIMIT 1');
    $address = $this->db()->query($q)->fetch();

    if ($address)
    {
      $addrStr = '';
      if ($address['street'] !== '')
        $addrStr .= $address['street'];
      if ($address['houseNumber'] !== '')
       $addrStr .= ' '.$address['houseNumber'];
      if ($address['city'] !== '')
      {
        if ($addrStr !== '')
          $addrStr .= ', ';
        $addrStr .= $address['city'];
      }

      $mapButtons = [];
      $addressPlace = $this->table->app()->loadItem($address['addressPlaceNdx'], 'services.locAddr.addrPlaces');
      if ($addressPlace)
      {
        if ($addressPlace['wgs84lat'] != 0.0 && $addressPlace['wgs84lng'] != 0.0)
        {
          $mapButtons = $this->mapButtonsWgs84($addressPlace['wgs84lat'], $addressPlace['wgs84lng']);
        }
      }

      $row = [
        'p1' => 'Adresa',
        'v1' => $addrStr,
        'v2' => $mapButtons,
      ];
      $t[] = $row;

      // --
      if ($address['saLaUnit10Ndx'] !== 0)
      {
        $admUnit10 = $this->table->app()->loadItem($address['saLaUnit10Ndx'], 'services.locAddr.laUnits');
        if ($admUnit10)
        {
          $row = [
            'p1' => 'ORP',
            'v1' => $admUnit10['fullName'],
            'v2' => '#'.$admUnit10['laUnitId'],
          ];
          $t[] = $row;
        }
      }
    }
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

	public function createContentBody ()
	{
    $this->addAdmUnit();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
