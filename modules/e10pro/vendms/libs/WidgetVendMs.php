<?php

namespace e10pro\vendms\libs;

use \Shipard\UI\Core\WidgetBoard, \e10\utils;


/**
 * Class WidgetVendMs
 */
class WidgetVendMs extends WidgetBoard
{
	var $vendmNdx = 0;
	var $vendMs = NULL;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = 'menu';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];


		$vme = new \e10pro\vendms\libs\VendMsEngine($this->app());
		$vme->setVendMs($this->vendmNdx);
		$vme->widgetId = $this->widgetId;
		$vme->createCodeOverview();

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $vme->code]);

		/*
		if (substr ($this->activeTopTab, 0, 5) === 'week-')
		{
			if ($viewerMode === 'menu')
				$this->composeCodeWeekMenu();
			elseif ($viewerMode === 'peoples')
				$this->composeCodeWeekPeoplesOrders();
			elseif ($viewerMode === 'supplier')
				$this->composeCodeWeekSupplierOrders();
		}
		*/
	}

	public function init ()
	{
		$this->vendMs = $this->app()->cfgItem('e10pro.vendms.vendms');

		$this->createTabs();

		parent::init();

		$parts = explode('-', $this->activeTopTab);
		$this->vendmNdx = intval($parts[1] ?? 0);
	}

	function createTabs ()
	{
		$tabs = [];

		foreach ($this->vendMs as $vm)
		{
			$icon = 'tables/mac.base.zones';
			$tabs['vm-'.$vm['ndx']] = ['icon' => $icon, 'text' => $vm['sn'], 'action' => 'load-vm-' . $vm['ndx']];
		}
		$this->toolbar = ['tabs' => $tabs];


		$this->toolbar = ['tabs' => $tabs];
		$rt = [
			'viewer-mode-menu' => ['text' =>'', 'icon' => 'system/iconCutlery', 'action' => 'viewer-mode-menu'],
			'viewer-mode-peoples' => ['text' =>'', 'icon' => 'system/iconUser', 'action' => 'viewer-mode-peoples'],
			'viewer-mode-supplier' => ['text' =>'', 'icon' => 'system/iconDelivery', 'action' => 'viewer-mode-supplier'],
		];

		$this->toolbar['rightTabs'] = $rt;
	}

	public function title()
	{
		return FALSE;
	}

	public function XXXsetDefinition ($d)
	{
		$this->definition = ['class' => 'e10pro.vendms.libs.WidgetVendms', 'type' => 'wkfWall e10-widget-dashboard'];
	}

	public function XXXwidgetType()
	{
		$viewerMode = 'menu';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];


		return $this->definition['type'].' '.$viewerMode;
	}
}
