<?php

namespace services\persons;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Viewer\TableViewPanel;


/**
 * Class TableAddress
 */
class TableAddress extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.persons.address', 'services_persons_address', 'Adresy');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['street']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['city']];

		return $hdr;
	}
}


/**
 * class ViewAddresses
 */
class ViewAddresses extends TableView
{
	public function init()
	{
		parent::init();
		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = [];

		if ($item['street'] !== '')
			$listItem ['t1'][] = ['text' => $item['street'], 'class' => 'label label-default'];

		$listItem ['t2'] = [];

		$city = ['text' => $item['city'], 'class' => 'label label-success'];
		if ($item['zipCode'])
			$city['suffix'] = $item['zipCode'];

		$listItem ['t2'][] = $city;

		$listItem['t3'] = [];
		$listItem ['t3'][] = ['text' => $item['personFullName'], 'class' => 'label label-default'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [addr].*');
		array_push($q, ', persons.[fullName] AS personFullName');
		array_push ($q, ' FROM [services_persons_address] AS [addr]');
		array_push($q, ' LEFT JOIN [services_persons_persons] AS persons ON [addr].[person] = [persons].ndx');
		array_push ($q, ' WHERE 1');

		$qv = $this->queryValues();

		// -- source
		$addrSourceUnknown = isset ($qv['addrSources']['unknown']);
		$addrSourceAres = isset ($qv['addrSources']['ares']);
		$addrSourceAresRzp = isset ($qv['addrSources']['aresrzp']);
		$addrSourceRzp = isset ($qv['addrSources']['rzp']);
		$addrSources = [];
		if ($addrSourceUnknown) array_push ($addrSources, 0);
		if ($addrSourceAres) array_push ($addrSources, 1);
		if ($addrSourceAresRzp) array_push ($addrSources, 2);
		if ($addrSourceRzp) array_push ($addrSources, 3);
		if (count($addrSources))
			array_push ($q, ' AND [addr].[source] IN %in', $addrSources);

		// -- standardized
		$addrStandardizedNone = isset ($qv['addrStandardized']['none']);
		$addrStandardizedFully = isset ($qv['addrStandardized']['fully']);
		$addrStandardizedPartly = isset ($qv['addrStandardized']['partly']);
		$addrStandardized = [];
		if ($addrStandardizedNone) array_push ($addrStandardized, 0);
		if ($addrStandardizedFully) array_push ($addrStandardized, 1);
		if ($addrStandardizedPartly) array_push ($addrStandardized, 2);
		if (count($addrStandardized))
			array_push ($q, ' AND [addr].[standardized] IN %in', $addrStandardized);


		// -- fulltext
		if ($fts != '')
		{
			/*
			array_push ($q, ' AND (');
      array_push ($q, '[cities].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
			*/
    }

    array_push ($q, ' ORDER BY ndx');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- address source
		$chbxAddrSources = [
			'unknown' => ['title' => 'Neznámý', 'id' => 'unknown'],
			'ares' => ['title' => 'Ares', 'id' => 'ares'],
			'aresrzp' => ['title' => 'Ares RŽP', 'id' => 'aresrzp'],
			'rzp' => ['title' => 'RŽP', 'id' => 'rzp'],
		];
		$paramsAddrSources = new \Shipard\UI\Core\Params ($this->app());
		$paramsAddrSources->addParam ('checkboxes', 'query.addrSources', ['items' => $chbxAddrSources]);
		$qry[] = ['id' => 'itemTypes', 'style' => 'params', 'title' => 'Zdroj', 'params' => $paramsAddrSources];

		// -- standardized address
		$chbxAddrStandardized = [
			'none' => ['title' => 'Ne', 'id' => 'none'],
			'fully' => ['title' => 'Ano', 'id' => 'fully'],
			'partly' => ['title' => 'Částečně', 'id' => 'partly'],

		];
		$paramsAddrStandardized = new \Shipard\UI\Core\Params ($this->app());
		$paramsAddrStandardized->addParam ('checkboxes', 'query.addrStandardized', ['items' => $chbxAddrStandardized]);
		$qry[] = ['id' => 'itemTypes', 'style' => 'params', 'title' => 'Standardizace adresy', 'params' => $paramsAddrStandardized];

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}



/**
 * class FormAddress
 */
class FormAddress extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('person');
			$this->addColumnInput ('type');
			$this->addColumnInput ('specification');
			$this->addColumnInput ('street');
			$this->addColumnInput ('city');
			$this->addColumnInput ('zipcode');
			$this->addColumnInput ('country');
			$this->addColumnInput ('natId');
			$this->addColumnInput ('natAddressGeoId');
			$this->addColumnInput ('validFrom');
			$this->addColumnInput ('validTo');
			$this->addColumnInput ('source');

			$this->addColumnInput ('standardized');
			$this->addColumnInput ('addressPlaceNdx');

			$this->addColumnInput ('saHouseNr1');
			$this->addColumnInput ('saHouseNr2');
			$this->addColumnInput ('saHouseNrLetter');
			$this->addColumnInput ('saHouseNr');
			$this->addColumnInput ('saZipCode');

			$this->addColumnInput ('saStreetName');
			$this->addColumnInput ('saCityName');
			$this->addColumnInput ('saCityPartName');
			$this->addColumnInput ('saCityPart2Name');

			$this->addColumnInput ('saStreetId');
			$this->addColumnInput ('saCityPartId');
			$this->addColumnInput ('saCityPart2Id');
			$this->addColumnInput ('saCityId');
			$this->addColumnInput ('saZipCodeId');

			$this->addColumnInput ('saLaUnit10Id');
			$this->addColumnInput ('saLaUnit11Id');

			$this->addColumnInput ('saStreetNdx');
			$this->addColumnInput ('saCityPartNdx');
			$this->addColumnInput ('saCityPart2Ndx');
			$this->addColumnInput ('saCityNdx');
			$this->addColumnInput ('saZipCodeNdx');
			$this->addColumnInput ('saLaUnit10Ndx');
			$this->addColumnInput ('saLaUnit11Ndx');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailAddress
 */
class ViewDetailAddress extends TableViewDetail
{
}
