<?php

namespace E10Pro\Meters;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableMeters
 */
class TableMeters extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.meters.meters', 'e10pro_meters_meters', 'Měřiče');
	}
}


/**
 * Class ViewMeters
 */
class ViewMeters extends TableView
{
	var $metersKinds;
	var $units;

	public function init ()
	{
		$this->metersKinds = $this->app()->cfgItem('e10pro.meters.kinds', NULL);
		$this->units = $this->app->cfgItem ('e10.witems.units');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		$this->createBottomTabs();

		parent::init();
	}

	public function createBottomTabs()
	{
		$rows = $this->db()->query ('SELECT * FROM [e10pro_meters_groups] WHERE [docState] != 9800 ORDER BY [order], [fullName]');
		$bt = [];
		$active = 1;
		foreach ($rows as $r)
		{
			$addParams = ['metersGroup' => $r['ndx']];
			$bt [] = ['id' => $r['ndx'], 'title' => $r['shortName'], 'active' => $active, 'addParams' => $addParams];
			$active = 0;
		}
		$bt[] = ['id' => '0', 'title' => 'Vše', 'active' => 0];

		if (count($bt))
			$this->setBottomTabs($bt);
		elseif (isset($bt[0]['addParams']['metersGroup']))
			$this->addAddParam ('lan', $bt[0]['addParams']['metersGroup']);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['id'];

		$listItem['t2'] = [];
		$listItem['t2'][] = ['text' => $this->metersKinds[$item['meterKind']]['sn'], 'class' => 'label label-default'];
		$listItem['t2'][] = ['text' => $this->units[$item['unit']]['shortcut'], 'class' => 'label label-default'];

		if ($item['sn'] != '')
			$listItem['t2'][] = ['text' => $item['sn'], 'class' => 'label label-default'];

		if ($item['placeName'])
		{
			$l = ['text' => $item['placeName'], 'icon' => 'tables/e10.base.places', 'class' => 'label label-default'];
			if ($item['placeDesc'] !== '')
				$l['suffix'] = $item['placeDesc'];

			$listItem['t2'][] = $l;
		}
		if ($item['woDocNumber'])
			$listItem['t2'][] = ['text' => $item['woDocNumber'], 'icon' => 'tables/e10mnf.core.workOrders', 'class' => 'label label-default'];

		if ($item['parentMeter'])
			$listItem['t2'][] = ['text' => $item['parentFullName'], 'icon' => 'user/arrowCircleUp', 'class' => 'label label-default'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = intval($this->bottomTabId());

		$q = [];
		array_push($q, 'SELECT [meters].*,');
		array_push($q, ' [places].[fullName] AS [placeName],');
		array_push($q, ' [wo].[title] AS [woTitle], [wo].[docNumber] AS [woDocNumber],');
		array_push($q, ' [parents].[fullName] AS [parentFullName]');
		array_push($q, ' FROM [e10pro_meters_meters] AS [meters]');
		array_push($q, ' LEFT JOIN [e10_base_places] AS [places] ON [meters].[place] = [places].[ndx]');
		array_push($q, ' LEFT JOIN [e10mnf_core_workOrders] AS [wo] ON [meters].[workOrder] = [wo].[ndx]');
		array_push($q, ' LEFT JOIN [e10pro_meters_meters] AS [parents] ON [meters].[parentMeter] = [parents].[ndx]');
		array_push($q, ' WHERE 1');

		if ($bottomTabId)
			array_push($q, ' AND [meters].[metersGroup] = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [meters].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [meters].[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [meters].[id] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [meters].[sn] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [places].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [wo].[docNumber] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [wo].[title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}
		$this->queryMain ($q, '[meters].', ['[meters].id', '[meters].[fullName]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailMeter
 */
class ViewDetailMeter extends TableViewDetail
{
}


/**
 * Class FormMeter
 */
class FormMeter extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('meterKind');
					$this->addColumnInput ('unit');
					$this->addColumnInput ('sn');
					$this->addColumnInput ('place');
					$this->addColumnInput ('placeDesc');
					$this->addColumnInput ('workOrder');
					$this->addColumnInput ('metersGroup');
					$this->addColumnInput ('parentMeter');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

