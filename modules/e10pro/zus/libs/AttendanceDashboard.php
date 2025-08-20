<?php

namespace e10pro\zus\libs;




use \Shipard\UI\Core\WidgetBoard;
use \e10doc\core\libs\GlobalParams;
use \Shipard\Utils\Utils;


/**
 * Class AttendanceDashboard
 */
class AttendanceDashboard extends WidgetBoard
{
  var $calendarYear = 0;
	var $calendarMonth = 0;
  var $periodBegin = NULL;
  var $periodEnd = NULL;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$panelId = $this->app->testGetParam('widgetPanelId');

		if ($panelId === 'main' || $panelId === '')
			$this->addContentViewer('e10pro.emps.workingHours', 'e10pro.zus.libs.AttendanceViewer',
                              [
                                'forceInitViewer' => 1,
                                'periodBegin' => $this->periodBegin->format('Y-m-d'),
                                'periodEnd' => $this->periodEnd->format('Y-m-d')
                              ]);
	}

	public function init ()
	{
    $this->addParam ('calendarMonth');
		//$this->createTabs();

		parent::init();

		$cm = $this->reportParams ['calendarMonth']['value'];
    $this->calendarYear = intval(substr($cm, 0, 4));
    $this->calendarMonth = intval(substr($cm, 4, 2));
		$this->periodBegin = utils::createDateTime(sprintf('%4d-%02d-01', $this->calendarYear, $this->calendarMonth));
		if (!$this->periodBegin)
		{
			return;
		}
		$this->periodEnd = Utils::createDateTime(sprintf('%4d-%02d-', $this->calendarYear, $this->calendarMonth).$this->periodBegin->format('t'));
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
