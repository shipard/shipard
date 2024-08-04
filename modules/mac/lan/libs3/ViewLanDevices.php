<?php

namespace mac\lan\libs3;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewPanel;
use \e10\base\libs\UtilsBase;


/**
 * class ViewLanDevices
 */
class ViewLanDevices extends TableView
{
	var $addresses;
	var $classification;
	var $osInfo = [];
	var $deviceInfo = [];

	var $lanModes;
	var $wifiModes;

	var $deviceKind = 0;

	var $usePropertyLink = FALSE;

	/** @var \mac\swlan\libs\SWDevicesUtils */
	var $swDeviceUtils;

	/** @var \mac\lan\libs\WatchdogsUtils */
	var $watchdogsUtils;

	var $devicesOSBadges = [];
	var $devicesWDBadges = [];

	public function init ()
	{
		parent::init();

		$this->swDeviceUtils = new \mac\swlan\libs\SWDevicesUtils($this->app());
		$this->watchdogsUtils = new \mac\lan\libs\WatchdogsUtils($this->app());

		$this->lanModes = $this->app()->cfgItem('mac.lan.devices.adLanModes');
		$this->wifiModes = $this->app()->cfgItem('mac.lan.devices.adWifiModes');

		if ($this->queryParam ('lan'))
			$this->addAddParam ('lan', $this->queryParam ('lan'));

		if ($this->app->model()->module('e10pro.property') !== FALSE)
			$this->usePropertyLink = TRUE;

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'off', 'title' => 'Sklad'];
		$mq [] = ['id' => 'archive', 'title' => 'Vyřazeno'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];

		$this->setMainQueries ($mq);

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['disableSNMP'] = $item ['disableSNMP'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		if ($item['id'] !== '')
			$listItem ['i1'] = ['text' => $item['id'], 'class' => 'id', 'suffix' => '#'.$item['ndx']];
		elseif ($item['evNumber'] !== '')
			$listItem ['i1'] = ['text' => $item['id'], 'class' => 'evNumber', 'suffix' => '#'.$item['ndx']];

		$props = [];

		$macDeviceType = $this->app()->cfgItem('mac.devices.types.'.$item['macDeviceType'], NULL);
		if (isset($macDeviceType['useFamily']) && $macDeviceType['useFamily'])
		{
			$macDeviceTypeCfg = $this->table->macDeviceTypeCfg($item['macDeviceType']);
			$mdtFamilyCfg = $macDeviceTypeCfg['families'][$item['mdtFamily']] ?? [];
			$mdtTypeCfg = $mdtFamilyCfg['types'][$item['mdtType']] ?? [];

			if (isset($macDeviceTypeCfg['name']) && isset($mdtTypeCfg['title']))
				$props[] = ['text' => $macDeviceTypeCfg['name'].' '.$mdtTypeCfg['title'], 'class' => 'label label-default'];

			$props[] = ['text' => $this->lanModes[$item['adLanMode']]['fn'], 'class' => 'label label-default', 'icon' => 'settings/network'];
			if ($item['adWifiMode'])
				$props[] = ['text' => $this->wifiModes[$item['adWifiMode']]['fn'], 'class' => 'label label-default', 'icon' => 'user/wifi'];
		}

		if ($item['nodeSupport'])
			$props[] = ['icon' => 'system/iconCheck', 'text' => 'node', 'class' => 'label label-info'];
		if ($item['monitored'])
			$props[] = ['icon' => 'tables/mac.lan.lans', 'text' => 'Netdata', 'class' => 'label label-info'];

		/*
		if ($item['placeFullName'])
		{
			$placeLabel = ['icon' => 'system/iconMapMarker', 'text' => $item['placeFullName'], 'class' => ''];
			if ($item['placeDesc'] !== '')
				$placeLabel['suffix'] = $item['placeDesc'];
			$props[] = $placeLabel;
		}
		elseif ($item['placeDesc'] !== '')
			$props[] = ['icon' => 'system/iconMapMarker', 'text' => $item['placeDesc'], 'class' => ''];
		*/

		if (count($props))
			$listItem['t2'] = $props;

		$props = [];
		if ($item['rackName'])
			$props[] = ['text' => $item['rackName'], 'icon' => 'icon-window-maximize', 'class' => ''];

		if ($item['lanShortName'])
			$props[] = ['text' => $item['lanShortName'], 'icon' => 'system/iconSitemap', 'class' => ''];
		else
			$props[] = ['text' => '!!!', 'icon' => 'system/iconSitemap', 'class' => ''];

		if (count($props))
			$listItem['i2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset($this->devicesOSBadges[$item ['pk']]))
		{
			if (!isset($item ['t3']))
				$item ['t3'] = [];
			$item ['t3'] = array_merge ($this->devicesOSBadges[$item ['pk']], $item ['t3']);
		}

		if (isset($this->devicesWDBadges[$item ['pk']]))
		{
			if (!isset($item ['t3']))
				$item ['t3'] = [];
			$item ['t3'][] = $this->devicesWDBadges[$item ['pk']]['first'];
		}

		if (isset ($this->classification [$item ['pk']]))
		{
			if (!isset($item ['t3']))
				$item ['t3'] = [];

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t3'] = array_merge ($item ['t3'], $clsfGroup);

		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT devices.*, places.fullName as placeFullName, lans.shortName as lanShortName,';

		array_push ($q, ' [racks].fullName AS rackName');
		array_push ($q, ' FROM [mac_lan_devices] as devices');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON devices.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_racks AS [racks] ON [devices].[rack] = [racks].ndx');

		if ($this->usePropertyLink)
			array_push ($q, ' LEFT JOIN e10pro_property_property AS property ON devices.property = property.ndx');

		array_push ($q, ' WHERE 1');

		// -- owner lan
		if ($this->queryParam ('lan'))
			array_push ($q, " AND [lan] = %i", $this->queryParam ('lan'));

		if ($this->deviceKind && is_array($this->deviceKind))
			array_push ($q, " AND [deviceKind] IN %in", $this->deviceKind);
		elseif ($this->deviceKind)
			array_push ($q, " AND [deviceKind] = %i", $this->deviceKind);

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
					'(devices.[fullName] LIKE %s', '%'.$fts.'%',
					' OR devices.[deviceTypeName] LIKE %s', '%'.$fts.'%',
					' OR devices.[id] LIKE %s', '%'.$fts.'%',
					' OR devices.[evNumber] LIKE %s', '%'.$fts.'%',
					')'
			);
			array_push ($q, ' OR EXISTS (SELECT ndx FROM mac_lan_devicesIfaces WHERE devices.ndx = device AND (ip LIKE %s OR mac LIKE %s))', '%'.$fts.'%', '%'.$fts.'%');
			array_push($q, ')');
		}

		$this->defaultQuery($q);

		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE devices.ndx = recid AND tableId = %s', 'mac.lan.devices');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		if (isset ($qv['kinds']))
			array_push ($q, " AND devices.[deviceKind] IN %in", array_keys($qv['kinds']));

		if (isset ($qv['lans']))
			array_push ($q, " AND devices.[lan] IN %in", array_keys($qv['lans']));

		// -- applications
		if (isset($qv['apps']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM mac_lan_devicesProperties WHERE devices.ndx = device');
			array_push ($q, ' AND ([property] = %i', 3, ' AND [i2] IN %in', array_keys($qv['apps']), ' AND [deleted] = 0', ')');
			array_push ($q, ')');
		}

		// property types
		if (isset ($qv['propertyTypes']))
			array_push ($q, " AND property.[propertyType] IN %in", array_keys($qv['propertyTypes']));

		if ($this->usePropertyLink)
		{
			// others - without property
			$withoutProperty = isset ($qv['others']['withoutProperty']);
			if ($withoutProperty)
				array_push($q, ' AND devices.[property] = 0');
			// others - with property
			$withProperty = isset ($qv['others']['withProperty']);
			if ($withProperty)
				array_push($q, ' AND devices.[property] != 0');
		}

		// SNMP
		$withoutSNMP = isset ($qv['problems']['withoutSNMP']);
		$oldSNMP = isset ($qv['problems']['oldSNMP']);
		if ($withoutSNMP && $oldSNMP)
		{
			array_push ($q, ' AND (');
			array_push ($q, ' (devices.disableSNMP = 0 AND NOT EXISTS (SELECT ndx FROM mac_lan_devicesInfo WHERE devices.ndx = device))');
			$dateLimit = new \DateTime('2 weeks ago');
			array_push ($q, ' OR (devices.disableSNMP = 0 AND EXISTS (SELECT ndx FROM mac_lan_devicesInfo WHERE devices.ndx = device AND mac_lan_devicesInfo.dateUpdate < %d))', $dateLimit);
			array_push ($q, ')');
		}
		elseif ($withoutSNMP)
			array_push ($q, ' AND (devices.disableSNMP = 0 AND NOT EXISTS (SELECT ndx FROM mac_lan_devicesInfo WHERE devices.ndx = device))');
		elseif ($oldSNMP)
		{
			$dateLimit = new \DateTime('2 weeks ago');
			array_push ($q, ' AND (devices.disableSNMP = 0 AND EXISTS (SELECT ndx FROM mac_lan_devicesInfo WHERE devices.ndx = device AND mac_lan_devicesInfo.dateUpdate < %d))', $dateLimit);
		}

		if ($mainQuery == 'off' || $mainQuery == 'archive')
		{
			if ($mainQuery == 'off')
				array_push($q, ' AND devices.[docState] = 9100');
			else
				array_push($q, ' AND devices.[docState] = 9000');
			array_push($q, ' ORDER BY devices.[fullName], devices.[ndx]', $this->sqlLimit ());
		}
		else
			$this->queryMain ($q, 'devices.', ['devices.[fullName]', 'devices.[ndx]']);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->swDeviceUtils->devicesOSBadges($this->pks, $this->devicesOSBadges);
		$this->watchdogsUtils->devicesBadges($this->pks, $this->devicesWDBadges);

		$this->addresses = $this->table->loadAddresses ($this->pks);
		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks, 'label label-info pull-right');
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		// -- lans
		$lans = $this->db()->query ('SELECT ndx, fullName FROM mac_lan_lans WHERE docStateMain != 4')->fetchPairs ('ndx', 'fullName');
		$lans['0'] = 'Žádná síť';
		$this->qryPanelAddCheckBoxes($panel, $qry, $lans, 'lans', 'Sítě');

		// -- kinds
		$kinds = [];
		foreach ($this->app()->cfgItem('mac.lan.devices.kinds') as $ndx => $k)
			$kinds[$ndx] = $k['name'];
		$this->qryPanelAddCheckBoxes($panel, $qry, $kinds, 'kinds', 'Typy zařízení');

		// -- SNMP
		$chbxProblems = [
			'withoutSNMP' => ['title' => 'Chybějící SNMP informace', 'id' => 'withoutSNMP'],
			'oldSNMP' => ['title' => 'Zastaralé SNMP informace', 'id' => 'oldSNMP'],
			//'unlicensedSW' => ['title' => 'Chybějící SW licence', 'id' => 'unlicensedSW'],
		];
		$paramsProblems = new \E10\Params ($this->app());
		$paramsProblems->addParam ('checkboxes', 'query.problems', ['items' => $chbxProblems]);
		$qry[] = ['id' => 'problems', 'style' => 'params', 'title' => 'Problémy', 'params' => $paramsProblems];

		// -- others
		if ($this->usePropertyLink)
		{
			$chbxOthers = [
				'withoutProperty' => ['title' => 'Bez evidence majetku', 'id' => 'withoutProperty'],
				'withProperty' => ['title' => 'S evidencí majetku', 'id' => 'withProperty']
			];
			$paramsOthers = new \E10\Params ($this->app());
			$paramsOthers->addParam('checkboxes', 'query.others', ['items' => $chbxOthers]);
			$qry[] = ['id' => 'errors', 'style' => 'params', 'title' => 'Ostatní', 'params' => $paramsOthers];

			// -- property types
			$q [] = 'SELECT DISTINCT propertyTypes.ndx, propertyTypes.shortName ';
			array_push($q, ' FROM [mac_lan_devices] as devices');
			array_push($q, ' LEFT JOIN e10pro_property_property AS property ON devices.property = property.ndx');
			array_push($q, ' LEFT JOIN e10pro_property_types AS propertyTypes ON property.propertyType = propertyTypes.ndx');
			array_push($q, ' WHERE devices.property != 0 AND property.propertyType != 0');
			$propertyTypes = $this->db()->query($q)->fetchPairs('ndx', 'shortName');
			$this->qryPanelAddCheckBoxes($panel, $qry, $propertyTypes, 'propertyTypes', 'Typy majetku');
		}

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createToolbar ()
	{
		$t = parent::createToolbar();
		unset ($t[0]);

		return $t;
	}
}

