<?php


namespace helpdesk\core\libs;

use \Shipard\UI\Core\WidgetBoard;


/**
 * Class Dashboard
 */
class Dashboard extends WidgetBoard
{
	var $treeMode = 0;
	//var $help = 'prirucka/11';

	/** @var  \helpdesk\core\TableSections */
	var $tableSections;
	var $usersSections;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '1';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		$parts = explode ('-', $this->activeTopTab);

		$this->addContentViewer('helpdesk.core.tickets', /*'plans.core.ViewItems'*/'helpdesk.core.libs.ViewTicketsGrid', ['section' => $parts[1], 'viewerMode' => $viewerMode]);
	}

	public function init ()
	{
		$this->tableSections = $this->app->table ('helpdesk.core.sections');

		parent::init();
	}

	function __createTabs ()
	{
		$tabs = [];

		$this->toolbar = ['tabs' => $tabs];
	}

	public function title()
	{
		return FALSE;
	}
}
