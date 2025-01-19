<?php

namespace services\locAddr;

use \Shipard\Utils\Utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail, \Shipard\Utils\Str;
use \Shipard\Viewer\TableViewPanel;

/**
 * class TableStreets
 */
class TableStreets extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.locAddr.streets', 'services_locAddr_streets', 'Ulice');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$idsLabels[] = ['text' => '#'.$recData ['ndx'], 'class' => 'label label-primary pull-right'];
		$idsLabels[] = ['text' => '_'.$recData ['streetId'], 'class' => 'label label-primary pull-right'];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => $idsLabels,
		];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewStreets
 */
class ViewStreets extends TableView
{
	public function init()
	{
		parent::init();
		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];

		$listItem ['i1'] = ['text' => '#'.$item['streetId'], 'class' => 'id'];

    $listItem ['t2'] = [];

		if ($item['cityFullName'])
			$listItem ['t2'][] = ['text' => $item['cityFullName'], 'class' => 'label label-default'];
		if ($item['laUnitOwner0FullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner0FullName'], 'class' => 'label label-success'];
    if ($item['laUnitOwner1FullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner1FullName'], 'class' => 'label label-primary'];
    if ($item['laUnitOwner2FullName'])
      $listItem ['t2'][] = ['text' => $item['laUnitOwner2FullName'], 'class' => 'label label-info'];


		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [streets].*,');
		array_push ($q, ' [cities].[fullName] AS [cityFullName],');
    array_push ($q, ' [laUnits2].[fullName] AS [laUnitOwner2FullName],');
    array_push ($q, ' [laUnits1].[fullName] AS [laUnitOwner1FullName],');
    array_push ($q, ' [laUnits0].[fullName] AS [laUnitOwner0FullName]');
		array_push ($q, ' FROM [services_locAddr_streets] AS [streets]');
		array_push ($q, ' LEFT JOIN [services_locAddr_cities] AS [cities] ON streets.city = cities.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits2] ON cities.laUnitOwner2 = laUnits2.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits1] ON cities.laUnitOwner1 = laUnits1.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits0] ON cities.laUnitOwner0 = laUnits0.ndx');

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
      array_push ($q, '[streets].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
    }

    array_push ($q, ' ORDER BY fullName, ndx');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
	}
}


/**
 * Class FormStreet
 */
class FormStreet extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
		$this->closeForm ();
	}
}

/**
 * Class ViewDetailStreet
 */
class ViewDetailStreet extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
