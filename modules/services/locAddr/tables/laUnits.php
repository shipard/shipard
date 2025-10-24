<?php

namespace services\locAddr;

use \Shipard\Utils\Utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail, \Shipard\Utils\Str;
use \Shipard\Viewer\TableViewPanel;

/**
 * class TableLAUnits
 */
class TableLAUnits extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.locAddr.laUnits', 'services_locAddr_laUnits', 'Administrativní členění');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$idsLabels[] = ['text' => '#'.$recData ['ndx'], 'class' => 'label label-primary pull-right'];
		$idsLabels[] = ['text' => '_'.$recData ['laUnitId'], 'class' => 'label label-primary pull-right'];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => $idsLabels,
		];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewLAUnits
 */
class ViewLAUnits extends TableView
{
	public function init()
	{
		parent::init();

		$bt = [];
		$bt [] = ['id' => 'ALL', 'title' => 'Vše', 'active' => 1];
		$bt [] = ['id' => '11', 'title' => 'ZUJ', 'active' => 0];
		$bt [] = ['id' => '10', 'title' => 'ORP', 'active' => 0];
		$bt [] = ['id' => '2', 'title' => 'OKR', 'active' => 0];
		$bt [] = ['id' => '1', 'title' => 'KRJ', 'active' => 0];
		$bt [] = ['id' => '0', 'title' => 'REG', 'active' => 0];
		$this->setBottomTabs ($bt);

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];

		$listItem ['i1'] = ['text' => '#'.$item['laUnitId'], 'class' => 'id'];

		$listItem ['t2'] = [];

		$levels = $this->table->columnInfoEnum('level');
		$listItem ['t2'][] = ['text' => $levels[$item['level']], 'class' => 'label label-success'];

		if ($item['laUnitOwner2FullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner2FullName'], 'class' => 'label label-info'];
		if ($item['laUnitOwner1FullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner1FullName'], 'class' => 'label label-primary'];
		if ($item['laUnitOwner0FullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner0FullName'], 'class' => 'label label-warning'];

		if ($item['municipalityPersonOid'] !== '')
			$listItem ['i2'][] = ['text' => 'IČ: '.$item['municipalityPersonOid'], 'class' => 'label label-default'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [laUnits].*, ');
		array_push ($q, ' [laUnits2].[fullName] AS [laUnitOwner2FullName], [laUnits1].[fullName] AS [laUnitOwner1FullName], [laUnits0].[fullName] AS [laUnitOwner0FullName]');
		array_push ($q, ' FROM [services_locAddr_laUnits] AS [laUnits]');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits2] ON laUnits.laUnitOwner2 = laUnits2.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits1] ON laUnits.laUnitOwner1 = laUnits1.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits0] ON laUnits.laUnitOwner0 = laUnits0.ndx');
		array_push ($q, ' WHERE 1');

		$btId = $this->bottomTabId ();
		if ($btId !== '' && $btId !== 'ALL')
			array_push ($q, ' AND [laUnits].[level] = %i', intval($btId));

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
      array_push ($q, '[laUnits].[fullName] LIKE %s', '%'.$fts.'%');
      array_push ($q, ' OR [laUnits].[municipalityPersonOid] LIKE %s', '%'.$fts.'%');
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
 * class FormLAUnit
 */
class FormLAUnit extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('level');
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('laUnitOwner0');
			$this->addColumnInput ('laUnitOwner1');
			$this->addColumnInput ('laUnitOwner2');
			$this->addColumnInput ('cityPart2');
			$this->addColumnInput ('city');
			$this->addColumnInput ('laUnitId');
		$this->closeForm ();
	}
}

/**
 * Class ViewDetailCity
 */
class ViewDetailLAUnit extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
