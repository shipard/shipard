<?php

namespace e10pro\property;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';


use \e10\utils, e10doc\core\e10utils;


/**
 * Class ReportPropertyList
 * @package e10pro\property
 */
class ReportPropertyList extends \e10doc\core\libs\reports\GlobalReport
{
	var $list = [];
	var $pks = [];

	/** @var \e10pro\property\TableProperty */
	var $tableProperty;
	var $groups;
	var $propertyKinds;
	var $propertyTypes;
	var $headerPropertyColumns = [];
	var $clsf;
	var $paramsValuesForHeader = [];

	var $qryPropertyTypes = [];
	var $qryPropertyGroups = [];
	var $qryPropertyGroupsTypes = [];


	function init()
	{
		$this->tableProperty = $this->app->table('e10pro.property.property');
		$this->propertyKinds = $this->tableProperty->columnInfoEnum('propertyKind');
		$this->groups = $this->app->cfgItem('e10pro.property.groups');

		$this->detectQryParams();

		$this->addParam ('calendarMonth', 'calendarPeriod', ['flags' => ['enableAll', 'years'], 'years' => $this->tableProperty->propertyYears()]);

		$this->addMyParams();
		$this->addPropertiesParams();

		parent::init();

		$this->setInfo('icon', 'report/propertyList');
		$this->setInfo('title', 'Seznam majetku');

		$this->paperOrientation = 'landscape';
	}

	public function createContent()
	{
		parent::createContent();
		$this->loadList();
		$this->loadProperties();

		$h = ['#' => '#', 'id' => 'InvČ', 'type' => 'Typ', 'name' => 'Název'];

		$qv = $this->queryValues();
		// -- columns
		if (isset ($qv['columns']))
		{
			if (isset ($qv['columns']['person']))
				$h['person'] = 'Osoba';
			if (isset ($qv['columns']['place']))
				$h['place'] = 'Místo';
			if (isset ($qv['columns']['dateStart']))
				$h['dateStart'] = 'Pořízeno';
			if (isset ($qv['columns']['age']))
				$h['age'] = 'Stáří';
			if (isset ($qv['columns']['priceIn']))
				$h['priceIn'] = '+Poř. cena';
		}

		if (count($this->headerPropertyColumns))
			$h += $this->headerPropertyColumns;

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->list]);
	}

	protected function loadList()
	{
		$cpNdx = $this->reportParams ['calendarPeriod']['value'];

		$q[] = 'SELECT property.*, types.shortName as typeName, statesPersons.fullName as statePersonName, statesPlaces.shortName as statePlaceName';
		array_push($q, ' FROM e10pro_property_property AS property');
		array_push($q, ' LEFT JOIN e10pro_property_types AS types ON property.propertyType = types.ndx');
		array_push($q, ' LEFT JOIN e10pro_property_states as states ON property.ndx = states.property');
		array_push($q, ' LEFT JOIN e10_persons_persons as statesPersons ON states.person = statesPersons.ndx');
		array_push($q, ' LEFT JOIN e10_base_places as statesPlaces ON states.place = statesPlaces.ndx');
		array_push($q, ' WHERE 1');

		array_push($q, ' AND property.docState = %i', 4000);

		if ($cpNdx != '0')
		{
			utils::calendarMonthQuery2('dateStart', $q, $cpNdx);
			$this->setInfo('param', 'Období pořízení', $this->reportParams ['calendarPeriod']['activeTitle']);
		}

		$qv = $this->queryValues();
		// -- types
		if (isset ($qv['propertyTypes']))
		{
			$ptNdx = array_keys($qv['propertyTypes']);
			array_push($q, " AND [propertyType] IN %in", $ptNdx);

			if (!isset($this->paramsValuesForHeader['types']))
				$this->paramsValuesForHeader['types'] = ['title' => 'Typy', 'values' => []];

			foreach ($ptNdx as $ndx)
				$this->paramsValuesForHeader['types']['values'][] = $this->propertyTypes[$ndx];
		}
		// -- groups
		if (isset ($qv['propertyGroups']))
		{
			$types = [];
			$groupsNdxs = array_keys($qv['propertyGroups']);
			foreach ($groupsNdxs as $groupNdx)
			{
				$types += $this->groups[$groupNdx]['types'];

				if (!isset($this->paramsValuesForHeader['groups']))
					$this->paramsValuesForHeader['groups'] = ['title' => 'Skupiny', 'values' => []];
				$this->paramsValuesForHeader['groups']['values'][] = $this->groups[$groupNdx]['sn'];
			}
			array_push($q, ' AND [propertyType] IN %in', $types);
		}
		// -- classification
		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE property.ndx = recid AND tableId = %s', 'e10pro.property.property');
			foreach ($qv['clsf'] as $grpId => $grpItems)
			{
				array_push($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');

				$pcid = 'clsf-'.$grpId;
				$cg = \E10\searchArray($this->clsf, 'id', $grpId);
				if (!isset($this->paramsValuesForHeader[$pcid]))
					$this->paramsValuesForHeader[$pcid] = ['title' => $cg['name'], 'values' => []];
				foreach ($grpItems as $itemNdx => $itemContent)
					$this->paramsValuesForHeader[$pcid]['values'][] = $cg['items'][$itemNdx]['title'];
			}
			array_push ($q, ')');
		}

		array_push($q, ' ORDER BY property.propertyId');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->pks[] = $r['ndx'];
			$item = [
					'id' => ['text' => $r['propertyId'], 'docAction' => 'edit', 'table' => 'e10pro.property.property', 'pk' => $r['ndx']],
					'name' => $r['fullName'], 'type' => $r['typeName'],
					'dateStart' => $r['dateStart'], 'age' => utils::dateage2($r['dateStart']),
					'priceIn' => $r['priceIn']
			];

			if ($r['statePersonName'])
				$item['person'] = $r['statePersonName'];
			if ($r['statePlaceName'])
				$item['place'] = $r['statePlaceName'];

			$this->list[$r['ndx']] = $item;
		}

		// add header params
		foreach($this->paramsValuesForHeader as $paramId => $paramContent)
			$this->setInfo('param', $paramContent['title'], implode(', ', $paramContent['values']));
	}

	protected function loadProperties ()
	{
		if (!count($this->pks))
			return;

		$qv = $this->queryValues();
		if (!isset($qv['properties']))
			return;
		$propertyColumns = array_keys($qv['properties']);

		$properties = \e10\base\getPropertiesTable ($this->app, 'e10pro.property.property', $this->pks);
		foreach ($properties as $propertyNdx => $propertyProperties)
		{
			foreach ($propertyProperties as $groupId => $properties)
			{
				foreach ($properties as $propertyId => $propertyValues)
				{
					if (!in_array($propertyId, $propertyColumns))
						continue;

					$values = [];
					foreach ($propertyValues as $pv)
						$values[] = $pv['value'];

					$this->list[$propertyNdx][$propertyId] = implode(', ', $values);
					if (!isset($this->headerPropertyColumns[$propertyId]))
						$this->headerPropertyColumns[$propertyId] = $pv['name'];
				}
			}
		}
	}

	protected function addMyParams()
	{
		// -- groups
		$propertyGroups = $this->db()->query ('SELECT ndx, shortName FROM e10pro_property_groups WHERE docStateMain != 4')->fetchPairs ('ndx', 'shortName');
		$this->qryPanelAddCheckBoxes($propertyGroups, 'propertyGroups', 'Skupiny majetku');

		// -- types
		$qryTypes[] = 'SELECT ndx, shortName FROM e10pro_property_types WHERE docStateMain != 4';
		if (count($this->qryPropertyGroupsTypes))
			array_push($qryTypes, ' AND ndx IN %in', $this->qryPropertyGroupsTypes);
		$this->propertyTypes = $this->db()->query ($qryTypes)->fetchPairs ('ndx', 'shortName');
		$this->qryPanelAddCheckBoxes($this->propertyTypes, 'propertyTypes', 'Typy majetku');

		// -- classification
		$this->clsf = \E10\Base\classificationParams ($this->tableProperty);
		foreach ($this->clsf as $cg)
		{
			$this->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items'], 'place' => 'panel', 'title' => $cg['name']]);
		}

		// -- columns
		$columns = ['person' => 'Osoba', 'place' => 'Místo', 'dateStart' => 'Datum pořízení', 'age' => 'Stáří', 'priceIn' => 'Poř. cena'];
		$this->qryPanelAddCheckBoxes($columns, 'columns', 'Zobrazit sloupce');
	}

	protected function addPropertiesParams()
	{
		$propertiesAll = $this->app->cfgItem ('e10.base.properties');
		$propertiesByTypes = $this->app->cfgItem ('e10pro.property.properties');

		$done = [];
		$propertyColumns = [];
		foreach ($propertiesByTypes as $typeNdx => $typeGroups)
		{
			if (!in_array($typeNdx, $this->qryPropertyTypes))
				continue;
			foreach ($typeGroups as $groupId => $groupProperties)
			{
				foreach ($groupProperties as $propertyId)
				{
					$id = $propertyId;
					if (in_array($id, $done))
						continue;
					$propertyColumns[$id] = $propertiesAll[$propertyId]['name'];
					$done[] = $id;
				}
			}
		}
		$this->qryPanelAddCheckBoxes($propertyColumns, 'properties', 'Zobrazit vlastnosti');
	}

	protected function detectQryParams ()
	{
		$qv = $this->queryValues();

		// -- groups
		if (isset ($qv['propertyGroups']))
		{
			$groupsNdxs = array_keys($qv['propertyGroups']);
			$this->qryPropertyGroups += $groupsNdxs;
			foreach ($groupsNdxs as $groupNdx)
			{
				$this->qryPropertyTypes = array_merge($this->qryPropertyTypes, $this->groups[$groupNdx]['types']);
				$this->qryPropertyGroupsTypes = array_merge ($this->qryPropertyGroupsTypes, $this->groups[$groupNdx]['types']);
			}
		}

		// -- types
		if (isset ($qv['propertyTypes']))
		{
			$this->qryPropertyTypes = array_merge ($this->qryPropertyTypes, array_keys($qv['propertyTypes']));
		}
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
				'text' => 'Uložit jako kompletní PDF soubor včetně příloh', 'icon' => 'icon-download',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'xpdf',
				'data-filename' => $this->saveAsFileName('xpdf')

		];
	}

	public function saveAsFileName ($type)
	{
		$fn = 'seznam-majetku';
		$fn .= '.pdf';
		return $fn;
	}

	public function saveReportAs ()
	{
		$this->loadList();

		$engine = new \lib\core\SaveDocumentAsPdf ($this->app);
		$engine->attachmentsPdfOnly = TRUE;

		foreach ($this->list as $ndx => $row)
		{
			$recData = $this->tableProperty->loadItem ($ndx);
			$engine->addDocument($this->tableProperty, $ndx, $recData, 'e10pro.property.ReportPropertyCard');
		}

		$engine->run();

		$this->fullFileName = $engine->fullFileName;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
	}
}
