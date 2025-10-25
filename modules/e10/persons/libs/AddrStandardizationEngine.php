<?php

namespace e10\persons\libs;

use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;
use \shipard\Utils\Json;


/**
 * class AddrStandardizationEngine
 */
class AddrStandardizationEngine extends Utility
{
  var $addressRecData = NULL;
  var $suggestions = [];

  public function baseApiRegUrl()
  {
    return 'https://data.shipard.app/';
  }

  public function setAddressRecData($addressRecData)
  {
    $this->addressRecData = $addressRecData;
  }

  public function loadSuggestions()
  {
    $url = $this->baseApiRegUrl();
    $url .= 'papi/la-addr-places-suggestions/?';

    $qryParams = [];
    if ($this->addressRecData['adrStreet'] !== '')
      $qryParams['street'] = $this->addressRecData['adrStreet'];
    if ($this->addressRecData['adrCity'] !== '')
      $qryParams['city'] = $this->addressRecData['adrCity'];
    if ($this->addressRecData['adrZipCode'] !== '')
      $qryParams['zipCode'] = $this->addressRecData['adrZipCode'];

    $url .= http_build_query($qryParams);

    $response = Utils::http_get($url);

		$responseContent = NULL;
		if (isset($response['content']))
			$responseContent = json_decode($response['content'], TRUE);

    if (isset($responseContent['success']) && $responseContent['success'] && isset($responseContent['object']['addrPlaces']))
    {
      foreach ($responseContent['object']['addrPlaces'] as $r)
      {
        $this->suggestions[] = $r;
      }
    }
  }

  public function getAddrPlace($countryNdx, $addrPlaceId)
  {
    $url = $this->baseApiRegUrl();
    $url .= 'papi/la-addr-place/';

    $url .= intval($countryNdx).'/'.intval($addrPlaceId);

    $response = Utils::http_get($url);

		$responseContent = NULL;
		if (isset($response['content']))
			$responseContent = json_decode($response['content'], TRUE);

    if (isset($responseContent['success']) && $responseContent['success'] && isset($responseContent['object']['addrPlace']))
      return $responseContent['object']['addrPlace'];

    return NULL;
  }

  public function addSugestionsContent($documentCard)
  {
    $t = [];

    $row = [
      'p1' => 'Návrhy na standardizaci', 'v1' => '', 'v2' => '',
			'_options' => ['colSpan' => ['p1' => 3], 'cellClasses' => ['p1' => 'h2'], 'cellCss' => ['p1' => 'text-align: left;']],
    ];
    $t[] = $row;

    foreach ($this->suggestions as $item)
    {
      $addrInfo = [];

      if ($item['streetFullName'])
        $addrInfo[] = ['text' => $item['streetFullName'], 'class' => 'label label-default'];

      $houseNr = ['text' => $item['houseNr'], 'class' => 'label label-default'];
      if ($item['houseNr1Type'])
        $houseNr['prefix'] = 'č.ev.';

      elseif ($item['houseNr1Type'] === 0 && (!$item['streetFullName'] || $item['streetFullName'] === '') /*&& !$item['cityPart']*/)
        $houseNr['prefix'] = 'č.p.';

      $addrInfo[] = $houseNr;

      $addrInfo[] = ['text' => '', 'class' => 'break'];

      if ($item['cityPart2FullName'])
        $addrInfo[] = ['text' => $item['cityPart2FullName'], 'class' => 'label label-warning'];

      $city = ['text' => $item['cityFullName'], 'class' => 'label label-success'];
      if ($item['zipCodeIdName'])
        $city['suffix'] = $item['zipCodeIdName'];
      if ($item['cityPartFullName'] && $item['cityPartFullName'] != $item['cityFullName'])
        $city['text'] .= ' - '.$item['cityPartFullName'];
      $addrInfo[] = $city;

      if ($item['admUnit2FullName'] && $item['admUnit2FullName'] != $city['text'])
        $addrInfo[] = ['text' => $item['admUnit2FullName'], 'class' => 'label label-info'];
      if ($item['admUnit1FullName'])
        $addrInfo[] = ['text' => $item['admUnit1FullName'], 'class' => 'label label-primary'];
      //if ($item['admUnit0FullName'] && $item['admUnit0FullName'] != $item['cityFullName'])
      //  $addrInfo[] = ['text' => $item['admUnit0FullName'], 'class' => 'label label-default'];


      // -- action button
      $actionButtons = [];

      $actionButtons[] = [
         'text' => 'Použít', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconCheck',
         'btnClass' => 'btn-default',
         'data-table' => 'e10.persons.persons', 'data-class' => 'e10.persons.libs.AddrStandardizationWizard',
         'data-addparams' => 'addrPlaceId=' . $item['addrPlaceId'].'&addressNdx='.$this->addressRecData['ndx'],
      ];

      $actionButtons[] = [
        'type' => 'action', 'action' => 'open-popup',
        'data-popup-url' => 'http://nahlizenidokn.cuzk.cz/MapaIdentifikace.aspx?l=KN&x=-'.intval($item['natGeoCoordY']).'&y=-'.intval($item['natGeoCoordX']),
        'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
        'text' => '', 'title' => 'Nahlížení do Katastru '.$x.', '.$y,
        'icon' => 'personDataBox', 'class' => 'pull-right',
      ];

      $y = $item['wgs84lng'];
      $x = $item['wgs84lat'];
      $actionButtons[] = [
        'type' => 'action', 'action' => 'open-popup',
        'data-popup-url' => 'https://mapy.cz/fnc/v1/showmap?center='.$y.','.$x.'&zoom=17'.'&marker=true',
        'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
        'text' => '', 'title' => 'mapy.cz - GPS: '.$x.', '.$y,
        'icon' => 'system/iconMapMarker', 'class' => 'pull-right',
      ];

      $row = [
        'p1' => $addrInfo,
        'v1' => $actionButtons,
        'v2' => '',
        '_options' => ['cellClasses' => ['v1' => 'width14em']],
      ];
      $t[] = $row;

    }

    $h = ['p1' => 'Vlastnost', 'v1' => '_V1'];
		$documentCard->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'header' => $h, 'table' => $t,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'default fullWidth']
		]);
  }

  public function standardizeAddressViaSuggestions($addressNdx)
  {
    /** @var \e10\persons\TablePersonsContacts $tablePersonsContacts */
    $tablePersonsContacts = $this->app()->table('e10.persons.personsContacts');
    $addrRec = $tablePersonsContacts->loadItem($addressNdx);
    if (!$addrRec)
      return 0;

    $addrStandardizationEngine = new \e10\persons\libs\AddrStandardizationEngine($this->app());
    $addrStandardizationEngine->setAddressRecData($addrRec);
    $addrStandardizationEngine->loadSuggestions();

    if (count($addrStandardizationEngine->suggestions) === 0)
    {
      if ($this->app()->debug)
        echo "no suggestion :-( ";

      return 0;
    }
    if (count($addrStandardizationEngine->suggestions) > 1)
    {
      if ($this->app()->debug)
        echo "to many suggestions: ".count($addrStandardizationEngine->suggestions);

      return 0;
    }

    $this->db()->query('UPDATE e10_persons_personsContacts SET docState = %i', 8000,
                          ', docStateMain = %i', 0, ' WHERE ndx = %i', $addressNdx);
    $tablePersonsContacts->docsLog($addrRec['ndx']);

    $tablePersonsContacts->applyAddrPlaceInReg($addrRec, $addrStandardizationEngine->suggestions[0]);
    $addrRec['flagStandardized'] = 1;

    $tablePersonsContacts->dbUpdateRec($addrRec);
    $tablePersonsContacts->docsLog($addrRec['ndx']);

    if ($this->app()->debug)
        echo "done";

    return 1;
  }

  public function standardizeAddress($addressNdx, $addrPlaceId)
  {
    /** @var \e10\persons\TablePersonsContacts $tablePersonsContacts */
    $tablePersonsContacts = $this->app()->table('e10.persons.personsContacts');
    $addrRec = $tablePersonsContacts->loadItem($addressNdx);
    if (!$addrRec)
      return 0;

    $countryNdx = intval($addrRec['country'] ?? 60);
    $addrPlace = $this->getAddrPlace($countryNdx, $addrPlaceId);
    if (!$addrPlace)
      return 0;

    $this->db()->query('UPDATE e10_persons_personsContacts SET docState = %i', 8000,
                          ', docStateMain = %i', 0, ' WHERE ndx = %i', $addressNdx);
    $tablePersonsContacts->docsLog($addrRec['ndx']);


    $tablePersonsContacts->applyAddrPlaceInReg($addrRec, $addrPlace);
    $addrRec['flagStandardized'] = 1;

    $tablePersonsContacts->dbUpdateRec($addrRec);
    $tablePersonsContacts->docsLog($addrRec['ndx']);

    return 1;
  }
}
