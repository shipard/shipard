<?php

namespace lib\Wkf;

use \E10\utils, \E10\Utility, \E10\uiutils;
use \Shipard\UI\Core\WidgetPane;

/**
 * Class BookingWidget
 * @package lib\Wkf
 */
class BookingWidget extends WidgetPane
{
	var $calParams;
	var $calParamsValues;
	var $viewType;
	var $today;

	public function createContent ()
	{
		$this->today = new \DateTime();

		$this->createContent_Toolbar();
		$this->viewType = 'month';//$this->calParamsValues['viewType']['value'];

		if ($this->calParamsValues['viewType']['value'] === 'agenda')
		{
			$vid = 'avsfshgfdhagfd';
			$this->addContent([
				'type' => 'viewer', 'table' => 'e10pro.booking.bookings', 'viewer' => 'e10pro.booking.ViewBookingAgenda',
				'params' => ['forceInitViewer' => 1, 'elementClass' => 'e10-widget-docViewer padd5'], 'vid' => $vid
			]);
		}
		else
		{
			$booking = new \lib\wkf\Booking($this->app);
			$booking->widgetId = $this->widgetId;
			$booking->setBookingType($this->calParamsValues['bookingType']['value']);

			if ($this->viewType === 'month')
				$booking->setMonthView(intval($this->calParamsValues['activeYear']['value']), intval($this->calParamsValues['activeMonth']['value']));
			else
			if ($this->viewType === 'year')
				$booking->setYearView(intval($this->calParamsValues['activeYear']['value']));

			$booking->init();
			$code = $booking->createCode ();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
		}
	}

	public function createContent_Toolbar ()
	{
		$this->calParams = new \E10\Params ($this->app);
		$viewTypes = ['month' => 'Měsíc', 'year' => 'Rok', 'agenda' => 'Agenda'];
		$this->addParamBookingTypes ();
		//$this->calParams->addParam ('switch', 'viewType', ['title' => 'Pohled', 'switch' => $viewTypes, 'radioBtn' => 1, 'defaultValue' => 'month']);
		$this->addParamMonth ();
		$this->addParamYear ();

		$this->calParams->detectValues ();
		$this->calParamsValues = $this->calParams->getParams();

		$c = '';

		$c .= "<div class='padd5' style='display: inline-block; width: 100%;'>";

		$btns = [];
		$btns[] = [
			'text' => 'Přidat', 'icon' => 'system/actionAdd', 'action' => 'new', 'data-table' => 'e10pro.booking.bookings',
			'type' => 'button', 'actionClass' => 'btn',
			'data-addParams' => '__bookingType='.$this->calParamsValues['bookingType']['value'],
			'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
		];
		$c .= $this->app()->ui()->composeTextLine($btns);
		$c .= '&nbsp;';

		$c .= $this->calParams->createParamCode('activeMonth');
		$c .= $this->calParams->createParamCode('activeYear');

		$c .= "<span class='pull-right'>";
		$c .= $this->calParams->createParamCode('bookingType');
		//$c .= '&nbsp;';
		//$c .= $this->calParams->createParamCode('viewType');
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
				$('#e10dashboardWidget').find ('table.e10-cal-year, table.e10-booking-plan').each (function () {
			      var oo = $(this).parent();
			    	oo.height(maxh - oo.position().top - 5);
				});
			</script>
			";

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	public function addParamBookingTypes ()
	{
		$enum = [];
		$bookingTypes = $this->app->cfgItem('e10pro.bookingTypes');
		foreach ($bookingTypes as $bt)
			if ($bt['ndx'])
				$enum[$bt['ndx']] = $bt['sn'];

		$this->calParams->addParam ('switch', 'bookingType', ['title' => 'Rezervace', 'switch' => $enum, 'radioBtn' => 1]);
	}

	public function addParamMonth ()
	{
		$viewType = 'month';//uiutils::detectParamValue('viewType', 'month');
		if ($viewType !== 'month')
			return;

		$months = [1 => 'leden',2 => 'únor',3 => 'březen',4 => 'duben',5 => 'květen',6 => 'červen',
			7 => 'červenec',8 => 'srpen',9 => 'září',10 => 'říjen',11 => 'listopad',12 => 'prosinec'];
		$this->calParams->addParam('switch', 'activeMonth', ['switch' => $months, 'defaultValue' => intval($this->today->format('m'))]);
	}

	public function addParamYear ()
	{
		$maxYear = intval($this->today->format('Y')) + 1;
		$minYear = intval($this->today->format('Y')) - 2;

		$years = [];
		for ($y = $maxYear; $y >= $minYear; $y--)
			$years[$y] = strval ($y);
		$this->calParams->addParam('switch', 'activeYear', ['switch' => $years, 'defaultValue' => $this->today->format('Y')]);
	}


	public function title() {return FALSE;}
}
