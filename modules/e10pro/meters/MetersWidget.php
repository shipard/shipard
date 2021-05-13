<?php

namespace E10Pro\Meters;

use \E10\utils, \E10\Utility, \E10\uiutils;
use \Shipard\UI\Core\WidgetPane;

/**
 * Class MetersWidget
 * @package E10Pro\Meters
 */
class MetersWidget extends WidgetPane
{
	var $calParams;
	var $calParamsValues;
	var $viewType;
	var $activeMeters = [];

	public function prepare ()
	{
		$q[] = 'SELECT * FROM [e10_base_doclinks] as links';
		array_push($q, ' WHERE [srcTableId] = %s', 'e10pro.meters.groups', ' AND [srcRecId] = %i', $this->calParamsValues['metersGroup']['value']);

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
			$this->activeMeters[] = $r['dstRecId'];
	}

	public function createContent ()
	{
		$this->createContent_Toolbar();
		$this->prepare();
		$this->viewType = $this->calParamsValues['viewType']['value'];

		if ($this->calParamsValues['viewType']['value'] === 'dashboard')
		{
			$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);
				$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 7]);
					$this->latestValues();
				$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
			$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);
			/*$vid = 'avsfshgfdhagfd';
			$this->addContent([
				'type' => 'viewer', 'table' => 'e10pro.booking.bookings', 'viewer' => 'e10pro.booking.ViewBookingAgenda',
				'params' => ['forceInitViewer' => 1, 'elementClass' => 'e10-widget-docViewer padd5'], 'vid' => $vid
			]);*/
		}
		/*
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
	*/
	}

	public function createContent_Toolbar ()
	{
		$this->calParams = new \E10\Params ($this->app);
		$viewTypes = ['dashboard' => 'Přehled', 'values' => 'Data'];
		$this->addParamMetersGroups ();
		$this->calParams->addParam ('switch', 'viewType', ['title' => 'Pohled', 'switch' => $viewTypes, 'radioBtn' => 1, 'defaultValue' => 'dashboard']);

		$this->calParams->detectValues ();
		$this->calParamsValues = $this->calParams->getParams();

		$c = '';

		$c .= "<div class='padd5' style='display: inline-block; width: 100%;'>";

		$btns = [];
		$btns[] = [
			'type' => 'action', 'action' => 'wizard', 'text' => 'Nový odečet', 'icon' => 'icon-plus-circle',
			'data-table' => 'e10.persons.persons', 'data-class' => 'e10pro.meters.MetersWizard',
			'data-addparams' => 'metersGroup='.$this->calParamsValues['metersGroup']['value'],
			'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
		];
		$c .= $this->app()->ui()->composeTextLine($btns);
		$c .= '&nbsp;';


		$c .= "<span class='pull-right'>";
		$c .= $this->calParams->createParamCode('metersGroup');
		$c .= '&nbsp;';
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
				$('#e10dashboardWidget').find ('table.e10-cal-year, table.e10-booking-plan').each (function () {
			      var oo = $(this).parent();
			    	oo.height(maxh - oo.position().top - 5);
				});
			</script>
			";

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	public function addParamMetersGroups ()
	{
		$enum = [];

		$q[] = 'SELECT * FROM e10pro_meters_groups as grps';
		array_push($q, ' WHERE docState != 9800');
		array_push($q, ' ORDER BY [order], fullName, ndx');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$enum[$r['ndx']] = $r['shortName'];
		}
		$this->calParams->addParam ('switch', 'metersGroup', ['title' => 'Měření', 'switch' => $enum, 'radioBtn' => 1]);
	}

	public function latestValues ()
	{
		$units = $this->app->cfgItem ('e10.witems.units');

		$q[] = 'SELECT vals.*, meters.shortName as meterName, meters.unit as meterUnit FROM e10pro_meters_values as vals';
		array_push ($q, ' LEFT JOIN [e10pro_meters_meters] AS meters ON vals.meter = meters.ndx');
		array_push ($q, ' WHERE vals.[meter] IN %in', $this->activeMeters);
		array_push ($q, ' ORDER BY [datetime] DESC, ndx DESC');

		array_push ($q, ' LIMIT 1000');

		$rows = $this->app->db()->query ($q);

		$data = [];
		$usedMeters = [];
		foreach ($rows as $r)
		{
			$rid = $r['datetime'] ? $r['datetime']->format ('YmdHi') : '!'.$r['ndx'];
			$vid = 'M'.$r['meter'];

			if (!isset ($usedMeters[$vid]))
				$usedMeters[$vid] = ['name' => $r['meterName'], 'unit' => $units[$r['meterUnit']]['shortcut']];

			if (!isset($data[$rid]))
				$data[$rid] = ['date' => ($r['datetime']) ? utils::datef ($r['datetime'], '%D, %T') : '!není datum!'];

			$data[$rid][$vid] = [
				'text'=> utils::nf ($r['value'], 1), 'docAction' => 'edit', 'table' => 'e10pro.meters.values', 'pk'=> $r['ndx'],
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
			];
		}

		if (!count($data))
			return;

		$h = ['date' => 'Datum'];
		foreach ($usedMeters as $mid => $meter)
			$h[$mid] = ' '.$meter['name'].' ['.$meter['unit'].']';

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'title' => 'Poslední odečty', 'main' => TRUE, 'pane' => 'e10-pane e10-pane-table']);
	}

	public function title() {return FALSE;}
}

