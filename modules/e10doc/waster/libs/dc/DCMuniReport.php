<?php

namespace e10doc\waster\libs\dc;
use \Shipard\Utils\World;


/**
 * class DCMuniReport
 */
class DCMuniReport extends \Shipard\Base\DocumentCard
{
	var ?\e10doc\waster\libs\ReportMuniReport $report = NULL;

	public function createContentBody ()
	{
		$this->addCity();
    $this->addItemRows ();
	}

  public function addItemRows ()
	{
		$content = $this->report->data['wasteRows'][0];
		$content['title'] = 'Přehled převzatých odpadů';
		$content['pane'] = 'e10-pane e10-pane-table';
		$this->addContent('body', $content);
	}

	protected function addCity()
	{
    $t = [];

		$spTitle = [
			['text' => 'Obec', 'class' => ''],
		];


    $row = [
      'p1' => $spTitle,
			'_options' => ['colSpan' => ['p1' => 3], 'cellClasses' => ['p1' => 'h2'], 'cellCss' => ['p1' => 'text-align: left;']],
    ];
    $t[] = $row;

		$personButtons[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://ares.gov.cz/ekonomicke-subjekty/ros/'.$this->report->data['muniPerson_identifier_oid'],
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'ROS', 'title' => 'Informace z registru osob pro IČ '.$this->report->data['muniPerson_identifier_oid'],
			'icon' => 'system/iconInfo', 'class' => 'pull-right',
		];
    $row = [
      'p1' => 'IČO',
      'v1' => $this->report->data['muniPerson_identifier_oid'],
			'v2' => $personButtons,
    ];
    $t[] = $row;

    $row = [
      'p1' => 'Osoba',
      'v1' => $this->report->data['muniPerson']['fullName'],
      'v2' => ['text' => '#'.$this->report->data['muniPerson']['id'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk' => $this->report->data['muniPerson']['ndx'], 'class' => 'btn btn-default', 'icon' => 'system/actionOpen']
    ];
    $t[] = $row;

		$x = $this->report->data['muniPerson']['address']['adrLocLat'];
		$y = $this->report->data['muniPerson']['address']['adrLocLon'];
		$addressButtons = [];
		$addressButtons[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://mapy.cz/fnc/v1/showmap?center='.$y.','.$x.'&zoom=17'.'&marker=true',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '', 'title' => 'mapy.cz - GPS: '.$x.', '.$y,
			'icon' => 'system/iconMapMarker', 'class' => 'pull-right',
		];
		$addressButtons[] = ['text' => '', 'docAction' => 'edit', 'table' => 'e10.persons.personsContacts', 'pk' => $this->report->data['muniPerson']['address']['ndx'], 'class' => 'btn btn-default', 'icon' => 'system/actionOpen'];
    $row = [
      'p1' => 'Sídlo',
      'v1' => $this->report->data['muniPerson']['address']['street'].', '.$this->report->data['muniPerson']['address']['city'],
      'v2' => $addressButtons,
    ];
    $t[] = $row;


		$admUnitButtons = [];
		$admUnitButtons[] = ['text' => '', 'docAction' => 'edit', 'table' => 'e10.world.admUnits', 'pk' => $this->report->data['wasteOriginAdmUnit']['ndx'], 'class' => 'btn btn-default', 'icon' => 'system/actionOpen'];
		$x = $this->report->data['wasteOriginAdmUnit']['wgs84lat'];
		$y = $this->report->data['wasteOriginAdmUnit']['wgs84lng'];
		$admUnitButtons[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://mapy.cz/fnc/v1/showmap?center='.$y.','.$x.'&zoom=17'.'&marker=true',
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => '', 'title' => 'mapy.cz - GPS: '.$x.', '.$y,
			'icon' => 'system/iconMapMarker', 'class' => 'pull-right',
		];
    $row = [
      'p1' => 'ZUJ',
      'v1' => $this->report->data['wasteOriginAdmUnit']['admUnitId'].' - '.$this->report->data['wasteOriginAdmUnit']['fullName'],
			'v2' => $admUnitButtons,
    ];
    $t[] = $row;


		// -- datová schránka
		$dataBoxId = $this->report->data['muniPerson_identifier_govDataBox'] ?? '';
		$dataBoxBtns[] = [
			'type' => 'action', 'action' => 'open-popup',
			'data-popup-url' => 'https://www.mojedatovaschranka.cz/sds/searchList?start=null&extendedSearch=no&searchCriterion=ovm_name_of_subject&searchValue='.$dataBoxId,
			'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
			'text' => 'ISDS', 'title' => 'Kontrola datové schránky: '.$dataBoxId,
			'icon' => 'system/iconInfo', 'class' => 'ml1',
		];

		//
    $row = [
      'p1' => 'Datová schránka',
      'v1' => $this->report->data['muniPerson_identifier_govDataBox'],
			'v2' => $dataBoxBtns,
    ];
    $t[] = $row;


		$h = ['p1' => 'Vlastnost', 'v1' => 'V1', 'v2' => 'V2'];
		$content = [
			'pane' => 'e10-pane e10-pane-table A2', 'header' => $h, 'table' => $t,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		];


		$this->addContent ('body', $content);
	}


	public function createContent ()
	{
		$this->report = new \e10doc\waster\libs\ReportMuniReport($this->table, $this->recData);
		$this->report->init();
		$this->report->loadData2 ();
		//$this->report->createReport ();

		$this->createContentBody ();
	}
}
