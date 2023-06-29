<?php

namespace wkf\events\libs;

use \Shipard\UI\Core\WidgetBoard;
use \Shipard\UI\Core\UIUtils;
use Shipard\Utils\Utils;


/**
 * class WidgetCalendar
 */
class WidgetCalendar extends WidgetBoard
{
	var $mobileMode;

	var $calParams;
	var $calParamsValues;
	var $viewType;
	var $today;
	var $defaultViewType = 'year';

	var $userCals = NULL;
	var $calendars;

	public function init ()
	{
		//$this->createTabs();
		/** @var \wkf\events\TableCals */
		$tableCals = $this->app()->table('wkf.events.cals');
		$this->userCals = $tableCals->usersCals();

		parent::init();
	}

	public function createContent ()
	{
		$this->today = new \DateTime();

		$this->createContent_Toolbar();
		$this->viewType = $this->calParamsValues['viewType']['value'];

		$this->panelStyle = self::psFixed;


		$this->calendars = $this->app()->cfgItem('wkf.events.cals', NULL);

		if (count($this->calendars) !== 0)
		{
			$chbxs = [];
			forEach ($this->calendars as $enumNdx => $r)
			{
				$cal = $this->calendars[$enumNdx] ?? NULL;
				$cssColor = ' border-left: 10px solid '.$cal['colorbg'].';';
				$chbxs[$enumNdx] = [
					'title' => $r['sn'], 'id' => $enumNdx,
					'css' => 'display: block;   text-align: left; background-color: transparent; border-radius: 0; margin-top: 2px;'.$cssColor,
				];
			}

			$this->addParam ('checkboxes', 'query.cals', ['items' => $chbxs, 'title' => 'Kalendáře', 'place' => 'panel']);
		}

		if (1)
		{
			$calendar = new \lib\wkf\Calendar($this->app);

			$qv = utils::queryValues();
			if (isset($qv['cals']))
				$calendar->enabledCalendars = array_keys($qv['cals']);

			if ($this->viewType === 'month')
				$calendar->setMonthView(intval($this->calParamsValues['activeYear']['value']), intval($this->calParamsValues['activeMonth']['value']));
			else
			if ($this->viewType === 'year')
				$calendar->setYearView(intval($this->calParamsValues['activeYear']['value']));
			else
			if ($this->viewType === 'week')
				$calendar->setWeekView(intval($this->calParamsValues['activeYear']['value']), $this->calParamsValues['activeWeek']['value']);

			$calendar->widgetId = $this->widgetId;
			$calendar->init();
			$calendar->loadEvents();
			$calCode = $calendar->createCode ();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $calCode]);

			return;
		}

		$this->addContent (['type' => 'line', 'line' => ['text' => 'Pokus 123: '.$this->activeTopTab]]);
	}

	public function createContent_Toolbar ()
	{
		$this->calParams = new \E10\Params ($this->app);
		$viewTypes = ['year' => 'Rok', 'month' => 'Měsíc', 'week' => 'Týden', /*'agenda' => 'Agenda'*/];
		$this->calParams->addParam('switch', 'viewType', ['title' => 'Pohled', 'switch' => $viewTypes, 'radioBtn' => 1, 'defaultValue' => $this->defaultViewType]);
		$this->addParamMonth ();
		$this->addParamWeek ();
		$this->addParamYear ();
		$this->calParams->detectValues ();

		$c = '';

		$c .= "<div id='e10-cal-tlbr' class='padd5' style='display: inline-block; width: 100%;'>";

		$btns = [];

		if ($this->userCals && count($this->userCals))
		{
			$addButton = [
				'action' => 'new', 'data-table' => 'wkf.events.events', 'icon' => 'system/actionAdd',
				'text' => 'Přidat', 'type' => 'button', 'actionClass' => 'btn',
				'class' => 'e10-param-addButton', 'btnClass' => 'btn-success',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId,
				//'data-addParams' => $addParams,
			];
			$btns[] = $addButton;
		}

		if (count($btns))
			$c .= $this->app()->ui()->composeTextLine($btns);

		$c .= $this->calParams->createParamCode('activeMonth');
		$c .= $this->calParams->createParamCode('activeYear');
		$c .= $this->calParams->createParamCode('activeWeek');

		$c .= "<span class='pull-right'>";
		$c .= $this->calParams->createParamCode('viewType');
		$c .= '</span>';

		$c .= '</div>';

		$c .= "<script>
				var maxh = $('#e10dashboardWidget').innerHeight();
				$('#e10dashboardWidget').find ('div.df2-viewer').each (function () {
			      var oo = $(this).parent();
			    	oo.height(maxh - oo.position().top - 15);
			      var viewerId = $(this).attr ('id');
						initViewer (viewerId);
				});
				$('#e10dashboardWidget').find ('table.e10-cal-big, table.e10-cal-year').each (function () {
			      var oo = $(this).parent();
			    	oo.height(maxh - oo.parent().offset().top - 15);
				});
			</script>
			";

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);

		$this->calParamsValues = $this->calParams->getParams();
	}

	public function addParamMonth ()
	{
		$viewType = UIUtils::detectParamValue('viewType', $this->defaultViewType);
		if ($viewType !== 'month')
			return;

		$months = [1 => 'leden',2 => 'únor',3 => 'březen',4 => 'duben',5 => 'květen',6 => 'červen',
								7 => 'červenec',8 => 'srpen',9 => 'září',10 => 'říjen',11 => 'listopad',12 => 'prosinec'];
		$this->calParams->addParam('switch', 'activeMonth', ['switch' => $months, 'defaultValue' => intval($this->today->format('m'))]);
	}

	public function addParamYear ()
	{
		$viewType = UIUtils::detectParamValue('viewType', $this->defaultViewType);
		if ($viewType !== 'year' && $viewType !== 'month' && $viewType !== 'week')
			return;

		$years = [];
		for ($y = 2023; $y <= 2025; $y++)
			$years[$y] = strval ($y);
		$this->calParams->addParam('switch', 'activeYear', ['switch' => $years, 'defaultValue' => $this->today->format('Y')]);
	}

	public function addParamWeek ()
	{
		$viewType = UIUtils::detectParamValue('viewType', $this->defaultViewType);
		if ($viewType !== 'week')
			return;

		$weekYear = UIUtils::detectParamValue('activeYear', utils::today('Y'));
		$thisWeekNumber = intval(strftime ('%V'));
		$thisWeekDate = '';

		$weeks = [];
		for ($y = 1; $y < 54; $y++)
		{
			$thisWeekYear = intval(Utils::weekDate ($y, $weekYear, 1, 'Y'));
			if ($thisWeekYear > $weekYear)
				break;
			$weekName = $y . ' (' . Utils::weekDate ($y, $weekYear, 1, 'd.m.') . ' - ' . Utils::weekDate ($y, $weekYear, 7, 'd.m.') . ')';
			$weekNumber = Utils::weekDate ($y, $weekYear);
			if ($thisWeekNumber === $y)
				$thisWeekDate = $weekNumber;
			$weeks[$weekNumber] = $weekName;
		}
		$this->calParams->addParam('switch', 'activeWeek', ['switch' => $weeks, 'defaultValue' => $thisWeekDate]);
	}

	public function title() {return FALSE;}
}
