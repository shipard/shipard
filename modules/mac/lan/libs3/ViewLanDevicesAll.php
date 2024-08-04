<?php

namespace mac\lan\libs3;

use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewLanDevicesAll
 */
 class ViewLanDevicesAll extends \mac\lan\libs3\ViewLanDevices
{
	var $groupParam = NULL;
	var $devicesGroups;

	/** @var \mac\lan\TableLans */
	var $tableLans;

	public function init ()
	{
		$this->usePanelLeft = TRUE;
		$this->linesWidth = 40;

		$enum = [];
		$this->devicesGroups = $this->app()->cfgItem ('mac.lan.devices.groupsNew');
		forEach ($this->devicesGroups as $dgNdx => $dg)
		{
			$enum[$dgNdx] = ['text' => $dg['name'], 'icon' => $dg['icon']];
		}

		$this->groupParam = new \E10\Params ($this->app);
		$this->groupParam->addParam('switch', 'devicesGroup', ['title' => '', 'switch' => $enum, 'list' => 1]);
		$this->groupParam->detectValues();

		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableLans->setViewerBottomTabs($this);

		parent::init();
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->groupParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->groupParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	function defaultQuery(&$q)
	{
		$groupNdx = intval($this->groupParam->detectValues()['devicesGroup']['value']);
		$devicesGroup = $this->devicesGroups[$groupNdx];

		$lan = intval($this->bottomTabId());
		if ($lan)
			array_push($q,' AND [devices].[lan] = %i', $lan);

		if (isset($devicesGroup['devicesKinds']))
			array_push ($q, ' AND devices.[deviceKind] IN %in', $devicesGroup['devicesKinds']);

		if (isset($devicesGroup['adLanModes']))
		{
			array_push ($q, ' AND (',
						'(devices.[deviceKind] = %i', 14, ' AND devices.[adLanMode] IN %in)', $devicesGroup['adLanModes'],
						' OR (devices.[deviceKind] != %i)', 14,
					')'
			);
		}

		if (isset($devicesGroup['adWifiModes']))
		{
			array_push ($q, ' AND (',
						'(devices.[deviceKind] = %i', 14, ' AND devices.[adWifiMode] IN %in)', $devicesGroup['adWifiModes'],
						' OR (devices.[deviceKind] != %i)', 14,
					')'
			);
		}

		parent::defaultQuery($q);
	}
}

