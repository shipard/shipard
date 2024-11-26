<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use \E10\utils, \E10\Utility, \E10\uiutils, \E10Pro\Zus\zusutils;


/**
 * Class SchoolDayDashboardWidget2
 */
class SchoolDayDashboardWidget2 extends \Shipard\UI\Core\WidgetPane
{
	var $calParams;
	var $calParamsValues;
	var $viewType;
	var $today;

	var $plan;

	var $enumLocalOffices;

	public function createContent ()
	{
		$this->today = new \DateTime();

		$this->createContent_Toolbar();

		if ($this->viewType === 'daily')
		{
			$todayId = $this->calParamsValues['day']['value'];

			$now = new \DateTime($todayId);
			$dow = intval($now->format('N')) - 1;

			$this->plan = new \e10pro\zus\PlanDailyTeachers($this->app);
			$this->plan->today = Utils::createDateTime($todayId);
			$this->plan->widgetId = $this->widgetId;
			$this->plan->setYear(zusutils::aktualniSkolniRok(), $dow);
			$this->plan->setLocalOffice($this->calParamsValues['localOffice']['value'], $this->calParamsValues['room']['value']);
			$this->plan->povolitNezaplanovaneVyuky = 0;
			$this->plan->enableWorkRecsColors = 1;
			$this->plan->init();

			$code = $this->plan->renderPlan();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
		}
	}

	public function createContent_Toolbar ()
	{
		$this->viewType = 'daily';

		$this->calParams = new \E10\Params ($this->app);
		$this->addParamLocalOffices();
		$this->addParamDays();
		$this->calParams->detectValues();
		$this->calParamsValues = $this->calParams->getParams();

		$c = '';
		$c .= "<div id='ttParams' class='padd5' style='display: inline-block; width: 100%;'>";

		if ($this->viewType === 'daily') {
			$c .= '&nbsp;';
			$c .= $this->calParams->createParamCode('day');
		}


		if ($this->viewType === 'localOffice' || $this->viewType === 'daily') {
			$c .= '&nbsp;';
			$c .= $this->calParams->createParamCode('localOffice');
		}


		$c .= '</div>';

		if ($this->viewType !== 'daily')
		{
			$c .= "<script>
					var maxh = $('#e10dashboardWidget').innerHeight();
					$('#e10dashboardWidget').find ('div.df2-viewer').each (function () {
							var oo = $(this).parent();
							oo.height(maxh - oo.position().top - 15);
							var viewerId = $(this).attr ('id');
							initViewer (viewerId);
					});
					$('#e10dashboardWidget').find ('table.e10-timetable').each (function () {
							var oo = $(this).parent();
							oo.height(maxh - oo.position().top - 5);
					});
				</script>
				";
		}

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	public function addParamDays ()
	{
		$days = [];
		$today = Utils::today();
		$todayId = $today->format('Y-m-d');
		$cnt = 0;
		while(1)
		{
			$dayId = $today->format('Y-m-d');
			$dow = intval($today->format('N')) - 1;

			if ($dow >= 5)
			{
				$today->sub(new \DateInterval('P1D'));
				continue;
			}

			$days[$dayId] = ['text' => Utils::$dayShortcuts[$dow], 'suffix' => Utils::datef($today, '%k')];
			$cnt++;
			$today->sub(new \DateInterval('P1D'));
			if ($cnt > 10)
				break;
		}

		$this->calParams->addParam ('switch', 'day', ['title' => 'Den', 'switch' => $days, 'radioBtn' => 1, 'defaultValue' => $todayId]);
	}

	public function X_addParamTeachers ()
	{
		$enum = zusutils::ucitele($this->app, FALSE);
		if ($this->app->hasRole('uctl'))
			$this->calParams->addParam ('switch', 'teacher', ['title' => 'Učitel', 'switch' => $enum, 'defaultValue' => $this->app->userNdx()]);
		else
			$this->calParams->addParam ('switch', 'teacher', ['title' => 'Učitel', 'switch' => $enum]);
	}

	public function addParamLocalOffices ()
	{
		$enableAll = TRUE;

		$enum = zusutils::pobocky($this->app, $enableAll);
		$this->calParams->addParam ('switch', 'localOffice', ['title' => 'Pobočka', 'switch' => $enum, 'defaultValue' => key($enum)]);

		$this->enumLocalOffices = $enum;
	}

	public function title() {return FALSE;}
}
