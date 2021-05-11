<?php

namespace mac\access\libs;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
use \Shipard\UI\Core\WidgetBoard, \e10\utils, \e10\Utility, \e10\uiutils;


/**
 * Class WidgetAccess
 * @package mac\access
 */
class WidgetAccess extends WidgetBoard
{
	var $today;
	var $mobileMode;

	public function init ()
	{
		$this->createTabs();

		parent::init();
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '0';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if ($this->activeTopTab === 'keys')
		{
			$this->addContentViewer('mac.access.tags', 'mac.access.libs.ViewerDashboardKeys', ['widgetId' => $this->widgetId, 'viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'persons')
		{
			$this->addContentViewer('mac.access.personsAccess', 'mac.access.libs.ViewerDashboardPersonsAccess', ['widgetId' => $this->widgetId, 'viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'log')
		{
			$this->addContentViewer('mac.access.log', 'default', ['widgetId' => $this->widgetId, 'viewerMode' => $viewerMode]);
		}

		$this->addContent (['type' => 'line', 'line' => ['text' => 'Pokus 123: '.$this->activeTopTab]]);
	}

	function createTabs ()
	{
		$tabs = [];

		$tabs['persons'] = ['icon' => 'icon-user', 'text' => 'Osoby', 'action' => 'load-persons'];
		$tabs['keys'] = ['icon' => 'icon-key', 'text' => 'Klíče', 'action' => 'load-keys'];
		$tabs['log'] = ['icon' => 'icon-eye', 'text' => 'Přístupy', 'action' => 'load-log'];

		$this->toolbar = ['tabs' => $tabs];


		$rt = [
			'viewer-mode-2' => ['text' =>'', 'icon' => 'icon-th', 'action' => 'viewer-mode-2'],
			'viewer-mode-1' => ['text' =>'', 'icon' => 'icon-th-list', 'action' => 'viewer-mode-1'],
			'viewer-mode-3' => ['text' =>'', 'icon' => 'icon-square', 'action' => 'viewer-mode-3'],
			'viewer-mode-0' => ['text' =>'', 'icon' => 'icon-th-large', 'action' => 'viewer-mode-0'],
		];
		$this->toolbar['rightTabs'] = $rt;
	}


	public function title() {return FALSE;}
}
