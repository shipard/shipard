<?php
namespace e10doc\waster\libs;

use \Shipard\UI\Core\WidgetBoard;
use \e10doc\core\libs\GlobalParams;


/**
 * class WasterDashboard
 */
class WasterDashboard extends WidgetBoard
{
	var $fiscalYear = 0;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$panelId = $this->app->testGetParam('widgetPanelId');

		if ($panelId === 'balance' || $panelId === '')
			$this->addContentViewer('e10pro.reports.waste_cz.returnRows', 'wasteCodesAnalysis', ['forceInitViewer' => 1, 'fiscalYear' => $this->fiscalYear]);
	}

	public function init ()
	{
		$this->addParam ('calendarMonth', 'fiscalYear', ['flags' => ['enableAll', 'years']]);
		//$this->createTabs();
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
