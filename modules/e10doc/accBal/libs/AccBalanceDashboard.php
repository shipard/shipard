<?php


namespace e10doc\accBal\libs;

use \Shipard\UI\Core\WidgetBoard;
use \e10doc\core\libs\GlobalParams;

/**
 * Class Dashboard
 */
class AccBalanceDashboard extends WidgetBoard
{
	var $fiscalYear = 0;

	var $treeMode = 0;
	//var $help = 'prirucka/11';

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$panelId = $this->app->testGetParam('widgetPanelId');

		if ($panelId === 'balance' || $panelId === '')
			$this->addContentViewer('e10doc.accBal.journal', 'default', ['forceInitViewer' => 1, 'fiscalYear' => $this->fiscalYear]);
	}

	public function init ()
	{
		$this->treeMode = 0;

		$this->addParam ('fiscalYear');

		$this->createTabs();

		parent::init();

		$this->fiscalYear = intval($this->reportParams ['fiscalYear']['value']);
	}

	function createTabs ()
	{
		$buttons = [];
		$buttons [] = [
			'type' => 'action', 'action' => 'addwizard',
			'text' => 'Přegenerovat', 'data-class' => 'e10doc.accBal.libs.ResetBalanceWizard',
			'icon' => 'cmnbkpRegenerateOpenedPeriod',
			'class' => 'btn-danger',
			'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId,
		];

		$this->toolbar = ['params' => [], 'buttons' => $buttons];
	}

	protected function createParamsObject ()
	{
		$this->params = new GlobalParams ($this->app);
	}


	public function title()
	{
		return FALSE;
	}
}
