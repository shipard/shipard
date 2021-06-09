<?php

namespace E10Pro\Property;

use \E10\TableView, \E10\TableViewDetail, \E10\FormReport;



function placePropertyList ($app, $placeNdx)
{
	$propStates = [];

	// -- TO
	$q = 'SELECT operations.property as property, prop.fullName as propName, prop.propertyId as propId, SUM(quantityTo) as q FROM e10pro_property_operations as operations '.
		'LEFT JOIN e10pro_property_property as prop ON operations.property = prop.ndx '.
		'WHERE placeTo = %i AND operations.docState = 4000 GROUP BY property';
	$propRows = $app->db()->query ($q, $placeNdx);
	foreach ($propRows as $r)
	{
		$propStates [$r['property']] = [
			'id' => ['text' => $r['propId'], 'docAction' => 'edit', 'table' => 'e10pro.property.property', 'pk' => $r['property']],
			'name' => $r['propName'], 'q' => $r['q']
		];
	}

	// -- FROM
	$q = 'SELECT operations.property as property, prop.fullName as propName, prop.propertyId as propId, SUM(quantityFrom) as q FROM e10pro_property_operations as operations '.
		'LEFT JOIN e10pro_property_property as prop ON operations.property = prop.ndx '.
		'WHERE placeFrom = %i AND operations.docState = 4000 GROUP BY property';
	$propRows = $app->db()->query ($q, $placeNdx);
	foreach ($propRows as $r)
	{
		if (isset ($propStates [$r['property']]))
			$propStates [$r['property']]['q'] += $r['q'];
		else
			$propStates [$r['property']] = [
				'id' => ['text' => $r['propId'], 'docAction' => 'edit', 'table' => 'e10pro.property.property', 'pk' => $r['property']],
				'name' => $r['propName'], 'q' => $r['q']
			];
	}

	if (count($propStates))
	{
		$h = array ('#' => '#', 'id' => 'Inv. č.', 'name' => 'Položka', 'q' => ' Množství');
		return ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $h, 'table' => $propStates, 'params' => ['precision' => 0]];
	}

	return NULL;
}


/**
 * Class ViewDetailPropertyPlace
 * @package E10Pro\Property
 */

class ViewDetailPropertyPlace extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->createDetailContent_PropertyList ();
	}

	public function createDetailContent_PropertyList ()
	{
		$list = placePropertyList ($this->app(), $this->item['ndx']);
		if ($list)
			$this->addContent ($list);
	}
}


/**
 * Class ReportPlaceProperty
 * @package E10Pro\Property
 */

class ReportPlaceProperty extends FormReport
{
	function init ()
	{
		$this->reportId = 'e10pro.property.place';
		$this->reportTemplate = 'e10pro.property.place';
	}

	public function loadData ()
	{
		$this->setInfo('icon', 'system/iconMapMarker');
		$this->setInfo('title', 'Inventář');
		$this->setInfo('param', 'Místo', $this->recData ['fullName']);

		$list = placePropertyList ($this->app, $this->recData ['ndx']);
		if ($list)
			$this->data ['property'] = $list;
	}
}

