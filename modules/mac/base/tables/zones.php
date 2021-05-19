<?php

namespace mac\base;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \e10\utils;


/**
 * Class TableZones
 * @package mac\base
 */
class TableZones extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.base.zones', 'mac_base_zones', 'Zóny');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);
		$this->checkTree (0, '', 0);
	}

	public function checkTree ($ownerNdx, $ownerPathId, $level)
	{
		$treeRows = $this->app()->db->query ('SELECT * FROM [mac_base_zones] WHERE [ownerZone] = %i', $ownerNdx, ' ORDER BY [order], [fullName]');
		$rowIndex = 1;
		forEach ($treeRows as $row)
		{
			$fullPathId = $ownerPathId . '/' . $row['pathId'];

			$rowUpdate ['pathLevel'] = $level;
			$rowUpdate ['fullPathId'] = $fullPathId;

			$this->app()->db->query ('UPDATE [mac_base_zones] SET ', $rowUpdate, ' WHERE [ndx] = %i', $row ['ndx']);
			$this->checkTree ($row ['ndx'], $fullPathId, $level + 1);

			$rowIndex++;
		}
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [mac_base_zones] WHERE docState != 9800 ORDER BY [order], [fullPathId]");
		$zones = [];
		$dashboards = [];
		foreach ($rows as $r)
		{
			$zone = [
				'ndx' => $r['ndx'], 'fn' => $r['fullName'], 'sn' => $r['shortName'], 'vs' => $r['toDashboardVs'], 'oz' => $r['ownerZone'],
				'cameras' => [],
			];

			$cntPeoples = 0;
			$cntPeoples += $this->saveConfigList ($zone, 'admins', 'e10.persons.persons', 'mac-zones-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($zone, 'adminsGroups', 'e10.persons.groups', 'mac-zones-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($zone, 'camerasUsers', 'e10.persons.persons', 'mac-zones-users-cameras', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($zone, 'camerasGroups', 'e10.persons.groups', 'mac-zones-users-cameras', $r ['ndx']);

			// -- cameras
			$camerasRows = $this->db()->query ('SELECT [ndx], [camera] FROM [mac_base_zonesCameras] WHERE [zone] = %i', $r['ndx'], ' ORDER BY [rowOrder], [ndx]');
			foreach ($camerasRows as $cr)
				$zone['cameras'][] = $cr['camera'];

			if ($r['toDashboardVs'])
			{
				$dashboardId = 'video-monitor-'.$zone['ndx'];
				$newDashboardItem = [
					'url' => '/app/'.$dashboardId,
					'objectType' => 'panel', 'zone' => 'sec', 'order' => 100000,
					'mainWidgetMode' => 1, 'hidden' => 1, 'disableRightMenu' => 1,
					'items' => [
						'monitor' => [
							't1' => 'Přehled', 'object' => 'widget', 'class' => 'e10.widgetDashboard',
							'subclass' => 'vs', 'subtype' => 'zone-'.$zone['ndx'],
							'icon' => 'icon-dashboard', 'order' => 1000
						]
					]
				];

				$dashboards[$dashboardId] = $newDashboardItem;
			}

			$zones[$r['ndx']] = $zone;
		}

		$cfg ['mac']['base']['zones'] = $zones;
		if (count($dashboards))
			$cfg ['appSkeleton']['panels'] = $dashboards;
		file_put_contents(__APP_DIR__ . '/config/_mac.base.zones.json', utils::json_lint (json_encode ($cfg)));
	}

	function saveConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
	{
		$list = [];

		$rows = $this->app()->db->query (
			'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', $listId, ' AND dstTableId = %s', $dstTableId,
			' AND doclinks.srcRecId = %i', $activityTypeNdx
		);
		foreach ($rows as $r)
		{
			$list[] = $r['dstRecId'];
		}

		if (count($list))
		{
			$item[$key] = $list;
			return count($list);
		}

		return 0;
	}

	function usersZones ($mainType, $ownerZone = 0)
	{
		// vs-main
		// vs-sub

		$zones = [];
		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		$allZones = $this->app()->cfgItem ('mac.base.zones', NULL);
		if ($allZones === NULL)
			return $zones;

		foreach ($allZones as $z)
		{
			if ($mainType === 'vs-main' && !$z['vs'])
				continue;
			if ($ownerZone && $z['oz'] !== $ownerZone)
				continue;

			$enabled = 0;


			if (isset($z['admins']) && in_array($userNdx, $z['admins'])) $enabled = 1;
			elseif (isset($z['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $z['adminsGroups'])) !== 0) $enabled = 1;

			if (!$enabled && $mainType === 'vs-sub')
			{
				if (isset($z['camerasUsers']) && in_array($userNdx, $z['camerasUsers'])) $enabled = 1;
				elseif (isset($u['camerasGroups']) && count($userGroups) && count(array_intersect($userGroups, $z['camerasGroups'])) !== 0) $enabled = 1;
			}
			if (!$enabled)
				continue;

			$zones[$z['ndx']] = $z;
		}

		return $zones;
	}
}


/**
 * Class ViewZones
 * @package mac\base
 */
class ViewZones extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['fullPathId'];
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem['level'] = $item['pathLevel'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [mac_base_zones]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[fullPathId]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailZone
 * @package mac\base
 */
class ViewDetailZone extends TableViewDetail
{
}


/**
 * Class FormZone
 * @package mac\base
 */
class FormZone extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Kamery', 'icon' => 'formCameras'];
		$tabs ['tabs'][] = ['text' => 'IoT', 'icon' => 'formIoT'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('pathId');
					$this->addColumnInput ('ownerZone');
					$this->addColumnInput ('order');
					$this->addColumnInput ('toDashboardVs');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addList ('cameras');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addList ('sc');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}
