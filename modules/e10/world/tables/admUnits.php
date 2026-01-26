<?php

namespace e10\world;

use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail, \Shipard\Utils\Str;
use \Shipard\Viewer\TableViewPanel;


/**
 * class TableAdmUnits
 */
class TableAdmUnits extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.admUnits', 'e10_world_admUnits', 'Administrativní členění');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$idsLabels[] = ['text' => '#'.$recData ['ndx'], 'class' => 'label label-primary pull-right'];
		$idsLabels[] = ['text' => '_'.$recData ['admUnitId'], 'class' => 'label label-primary pull-right'];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => $idsLabels,
		];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewAdmUnits
 */
class ViewAdmUnits extends TableView
{
	var $fixedLevel = 0;

	public function init()
	{
		parent::init();

    $this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if (!$this->fixedLevel)
		{
			$bt = [];
			$bt [] = ['id' => 'ALL', 'title' => 'Vše', 'active' => 1];
			$bt [] = ['id' => '11', 'title' => 'ZUJ', 'active' => 0];
			$bt [] = ['id' => '10', 'title' => 'ORP', 'active' => 0];
			$bt [] = ['id' => '2', 'title' => 'OKR', 'active' => 0];
			$bt [] = ['id' => '1', 'title' => 'KRJ', 'active' => 0];
			$bt [] = ['id' => '0', 'title' => 'REG', 'active' => 0];
			$this->setBottomTabs ($bt);
		}

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];

		$listItem ['i1'] = ['text' => '#'.$item['admUnitId'], 'class' => 'id'];

		$listItem ['t2'] = [];

		$levels = $this->table->columnInfoEnum('level');
		if (!$this->fixedLevel)
			$listItem ['t2'][] = ['text' => $levels[$item['level']], 'class' => 'label label-success'];

		if ($item['admUnitOwner2FullName'])
			$listItem ['t2'][] = ['text' => $item['admUnitOwner2FullName'], 'class' => 'label label-info'];
		if ($item['admUnitOwner1FullName'])
			$listItem ['t2'][] = ['text' => $item['admUnitOwner1FullName'], 'class' => 'label label-primary'];
		if ($item['admUnitOwner0FullName'])
			$listItem ['t2'][] = ['text' => $item['admUnitOwner0FullName'], 'class' => 'label label-warning'];

		if ($item['municipalityPersonOid'] !== '')
		{
			$listItem ['i2'][] = ['text' => 'IČ: '.$item['municipalityPersonOid'], 'class' => 'label label-default'];
		}

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [admUnits].*, ');
		array_push ($q, ' [admUnits2].[fullName] AS [admUnitOwner2FullName], [admUnits1].[fullName] AS [admUnitOwner1FullName], [admUnits0].[fullName] AS [admUnitOwner0FullName]');
		array_push ($q, ' FROM [e10_world_admUnits] AS [admUnits]');
		array_push ($q, ' LEFT JOIN [e10_world_admUnits] AS [admUnits2] ON admUnits.admUnitOwner2 = admUnits2.ndx');
		array_push ($q, ' LEFT JOIN [e10_world_admUnits] AS [admUnits1] ON admUnits.admUnitOwner1 = admUnits1.ndx');
		array_push ($q, ' LEFT JOIN [e10_world_admUnits] AS [admUnits0] ON admUnits.admUnitOwner0 = admUnits0.ndx');
		array_push ($q, ' WHERE 1');

		if ($this->fixedLevel)
		{
			array_push ($q, ' AND [admUnits].[level] = %i', $this->fixedLevel);
		}
		else
		{
			$btId = $this->bottomTabId ();
			if ($btId !== '' && $btId !== 'ALL')
				array_push ($q, ' AND [admUnits].[level] = %i', intval($btId));
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
      array_push ($q, '[admUnits].[fullName] LIKE %s', '%'.$fts.'%');

			$numId = intval($fts);
			if ($numId == intval($fts))
			{
				array_push ($q, ' OR [admUnits].[admUnitId] = %i', $numId);
			}

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
 * class ViewAdmUnitsL11
 */
class ViewAdmUnitsL11 extends ViewAdmUnits
{
	public function init()
	{
		$this->fixedLevel = 11;
		parent::init();
	}
}


/**
 * class FormAdmUnit
 */
class FormAdmUnit extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('level');
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('admUnitOwner0');
			$this->addColumnInput ('admUnitOwner1');
			$this->addColumnInput ('admUnitOwner2');
			$this->addColumnInput ('admUnitOwner10');
			$this->addColumnInput ('cityPart2');
			$this->addColumnInput ('admUnitId');
			$this->addColumnInput ('municipalityPersonOid');
			$this->addColumnInput ('municipalityPerson');
			$this->addColumnInput ('wgs84lat');
			$this->addColumnInput ('wgs84lng');
		$this->closeForm ();
	}
}

/**
 * Class ViewDetailAdmUnit
 */
class ViewDetailAdmUnit extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
