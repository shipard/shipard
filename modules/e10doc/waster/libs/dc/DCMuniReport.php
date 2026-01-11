<?php

namespace e10doc\waster\libs\dc;
use \Shipard\Utils\World;
use \Shipard\Utils\Utils, wkf\core\TableIssues;


/**
 * class DCMuniReport
 */
class DCMuniReport extends \Shipard\Base\DocumentCard
{
	var ?\e10doc\waster\libs\ReportMuniReport $report = NULL;
	protected $linkedAttachments = [];

	public function createContentBody ()
	{
		$this->addCity();
    $this->addItemRows ();
		$this->addAttachments();
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

	public function addAttachments ()
	{
		$docsFrom = [];
		$docsTo = [];
		$this->linkedInboxOutbox($docsFrom, $docsTo);

		$this->addContentAttachments ($this->recData ['ndx']);

		foreach ($this->linkedAttachments as $la)
			$this->addContentAttachments ($la ['recid'], $la ['tableid'], $la ['title'], $la ['downloadTitle']);
	}

	function linkedInboxOutbox(&$docsFrom, &$docsTo)
	{
		if (!isset($this->recData['ndx']) || !$this->recData['ndx'])
			return;

		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app()->table ('wkf.core.issues');

		$q = [];
		array_push($q, 'SELECT * FROM [wkf_core_issues]');
		array_push($q, ' WHERE (tableNdx = %i', $this->table->ndx, ' AND recNdx = %i', $this->recData['ndx'], ')');
		array_push($q, ' ORDER BY dateCreate DESC, ndx DESC');
		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			if ($r['docState'] === 9800)
				continue; // deleted
			$dateStr = $r['dateIncoming'] ? Utils::datef ($r['dateIncoming']) : Utils::datef ($r['date']);
			$msgItem = ['icon' => $tableIssues->tableIcon ($r), 'text' => '#'.$r['ndx'], 'class' => 'tag tag-contact',
				'prefix' => $dateStr,
				'docAction' => 'edit', 'table' => 'wkf.core.issues', 'pk' => $r['ndx']];
			if ($r['issueType'] === TableIssues::mtInbox)
			{
				$msgItem['title'] = 'Došlá pošta: '.$r['subject'];
				$docsFrom[] = $msgItem;
			}
			elseif ($r['issueType'] === TableIssues::mtOutbox)
			{
				$msgItem['title'] = 'Odeslaná pošta: '.$r['subject'];
				$docsTo[] = $msgItem;
			}
			else
			{
				$msgItem['title'] = 'TEST: '.$r['subject'];
				$docsTo[] = $msgItem;
			}

			$laTitleLeft = ['icon' => 'system/formAttachments', 'text' => 'Přílohy'];
			$laTitleRight = $msgItem;
			$laTitleRight ['class'] = 'pull-right';

			$laDownloadTitleLeft = ['icon' => 'system/actionDownload', 'text' => 'Soubory ke stažení'];
			$laDownloadTitleRight = $msgItem;
			$laDownloadTitleRight ['class'] = 'pull-right';

			$this->linkedAttachments[] = [
				'tableid' => 'wkf.core.issues', 'recid' => $r['ndx'],
				'title' => [$laTitleLeft, $laTitleRight], 'downloadTitle' => [$laDownloadTitleLeft, $laDownloadTitleRight]
			];
		}
	}

	public function createContent ()
	{
		$this->report = new \e10doc\waster\libs\ReportMuniReport($this->table, $this->recData);
		$this->report->init();
		$this->report->loadData2 ();

		$this->createContentBody ();
	}
}
