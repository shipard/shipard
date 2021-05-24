<?php

namespace e10pro\property;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \E10\utils, \E10\TableView, \E10\TableViewDetail, \Shipard\Viewer\TableViewPanel, \Shipard\Form\TableForm, \E10\DbTable, \e10doc\core\e10utils;
use \e10doc\core\libs\GlobalParams;

/**
 * Class TableProperty
 */
class TableProperty extends DbTable
{
	CONST pkSingle = 0, pkStock = 1, pkXXX = 2;
	CONST pcShortTerm = 0, pcLongTermTangible = 1, pcLongTermIntangible = 2, pcLongTermLanded = 3, pcLeasing = 4;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.property.property", "e10pro_property_property", "Majetek", 1131);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$itemTop = array();

		if ($recData['propertyType'])
		{
			$pt = $this->app()->loadItem ($recData['propertyType'], 'e10pro.property.types');
			$itemTop[] = array ('text' => $pt['fullName']);
		}
		//if ($recData['brand'])
		//{
		//	$brand = $this->app()->loadItem($recData['brand'], 'e10.witems.brands');
		//	$itemTop[] = array ('text' => $brand['fullName']);
		//}

		$hdr ['info'][] = array ('class' => 'info', 'value' => $itemTop);
		$hdr ['info'][] = array ('class' => 'title', 'value' => $recData ['fullName']);

		$image = \E10\Base\getAttachmentDefaultImage ($this->app(), $this->tableId(), $recData ['ndx']);
		if (isset ($image ['smallImage']))
			$hdr ['image'] = $image ['smallImage'];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset ($recData['ndx']) && $recData['propertyId'] == '' && $recData['docState'] == 4000)
			$recData['propertyId'] = $this->propertyId ($recData);

		if (isset($recData['propertyType']) && $recData['propertyType'] != 0)
		{
			$pt = $this->loadItem($recData['propertyType'], 'e10pro_property_types');
			if ($pt['propertyKind'] != 99)
				$recData['propertyKind'] = $pt['propertyKind'];
		}
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if ($columnId == 'debsGroup')
		{
			if (!$form || !isset ($cfgItem ['groupKind']))
				return TRUE;

			if ($form->recData['propertyCategory'] == 0 && $cfgItem ['groupKind'] != 2)
				return FALSE;
			if ($form->recData['propertyCategory'] != 0 && $cfgItem ['groupKind'] != 1)
				return FALSE;

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);
		$recData ['propertyId'] = '';
		return $recData;
	}

	function disabledDetails ($viewerId, $detailId, $recData)
	{
		$dd = ['disabled' => []];

		if (!$this->useDepreciations($recData) || !$this->app()->hasRole('acc'))
			$dd['disabled'] += ['depsTax', 'depsAcc', 'dt'];

		if ($recData['propertyCategory'] != self::pcLongTermLanded || !$this->app()->hasRole('acc'))
			$dd['disabled'][] = 'nonDeps';

		if (in_array($detailId, $dd['disabled']))
			$dd['activate'] = ($viewerId === 'debs') ? 'debs' : 'default';

		return $dd;
	}

	public function propertyId ($recData)
	{
		$prefixes = ['MA', 'MK', 'MS'];
		$p = $prefixes[$recData['propertyKind']];
		if ($recData['ndx'] < 1000)
			return sprintf($p.'%04d', $recData['ndx']);
		return $p.$recData['ndx'];
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['propertyKind'] == TableProperty::pkStock)
			return 'icon-th-list';
		if ($recData['propertyKind'] == TableProperty::pkXXX)
			return 'icon-cubes';

		if ($recData['propertyCategory'] === self::pcShortTerm || $recData['propertyCategory'] === self::pcLeasing)
			return 'icon-cube';
		if ($recData['propertyCategory'] === self::pcLongTermLanded)
			return 'icon-bars';

		return parent::tableIcon ($recData, $options);
	}

	public function updateState ($propertyNdx)
	{
		$newState = ['property' => $propertyNdx, 'quantity' => 0, 'person' => 0, 'centre' => 0, 'place' => 0];

		// -- quantity
		$q = 'SELECT SUM(quantitySigned) as cnt FROM [e10pro_property_operations] WHERE [property] = %i AND docState = 4000';
		$cntRec = $this->app()->db()->query ($q, $propertyNdx)->fetch();
		if (isset ($cntRec['cnt']))
			$newState['quantity'] = $cntRec['cnt'];

		// -- persons etc.
		$propertyRecData = $this->loadItem($propertyNdx);
		if ($propertyRecData['propertyKind'] !== TableProperty::pkStock)
		{
			if ($newState['quantity'] != 0)
			{
				$q = 'SELECT person, centre, placeTo FROM [e10pro_property_operations] WHERE [property] = %i AND docState = 4000 ORDER BY [date] DESC, rowType DESC LIMIT 0, 1';
				$stateRec = $this->app()->db()->query ($q, $propertyNdx)->fetch();
				if (isset ($stateRec))
				{
					$newState['person'] = $stateRec['person'];
					$newState['centre'] = $stateRec['centre'];
					$newState['place'] = $stateRec['placeTo'];
				}
			}
		}

		//-- save
		$stateRec = $this->app()->db()->query ('SELECT * FROM e10pro_property_states WHERE property = %i', $propertyNdx)->fetch();
		if (isset ($stateRec['property']))
			$this->app()->db()->query ('UPDATE [e10pro_property_states] SET', $newState, ' WHERE property = %i', $propertyNdx);
		else
			$this->app()->db()->query ('INSERT INTO [e10pro_property_states]', $newState);
	}

	public function useDepreciations ($recData, $strict = TRUE)
	{
		if ($recData['propertyCategory'] === self::pcLongTermTangible || $recData['propertyCategory'] === self::pcLongTermIntangible)
			return TRUE;
		if ($recData['propertyCategory'] === self::pcLongTermLanded && !$strict)
			return TRUE;
		return FALSE;
	}

	function propertyYears($columnId = 'dateStart')
	{
		$years = utils::calendarMonths($this->app());

		$q[] = 'SELECT DISTINCT YEAR('.'['.$columnId.']'.') AS y ';
		array_push($q, ' FROM e10pro_property_property');
		array_push($q, ' WHERE ', '['.$columnId.']', ' IS NOT NULL');
		array_push($q, ' AND docState = %i', 4000);
		array_push($q, ' ORDER BY ', '['.$columnId.']', ' DESC');
		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			if (!in_array($r['y'], $years))
				$years[] = $r['y'];
		}

		return $years;
	}
}


/**
 * Class ViewProperty
 */
class ViewProperty extends TableView
{
	var $classification;
	var $groups;

	public function init ()
	{
		$this->groups = $this->app()->cfgItem('e10pro.property.groups');

		parent::init();

		$mq [] = ['id' => 'active', 'title' => 'Aktivní ABC'];
		$mq [] = ['id' => 'activeById', 'title' => 'Aktivní IČ', 'side' => 'left'];

		$mq [] = ['id' => 'inuse', 'title' => 'Zařazeno'];
		$mq [] = ['id' => 'discarded', 'title' => 'Vyřazeno'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		if ($this->viewId === 'debs')
		{
			$bt [] = [
				'id' => 'tangible', 'title' => 'Hmotný', 'active' => 1,
				'addParams' => ['propertyCategory' => 1, 'depreciationType' => 'AR', 'depreciationGroup' => 'A1']
			];
			$bt [] = [
				'id' => 'intangible', 'title' => 'Nehmotný',
				'addParams' => ['propertyCategory' => 1, 'depreciationGroup' => '0A']
			];
			$bt [] = [
				'id' => 'landed', 'title' => 'Neodepisovaný',
				'addParams' => ['propertyCategory' => 3]
			];
			$bt [] = [
				'id' => 'deps', 'title' => 'Vše',
			];
			$this->setBottomTabs($bt);
		}

		$this->setPanels (TableView::sptQuery);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$bottomQuery = $this->bottomTabId();

		$q [] = 'SELECT property.*, types.fullName as typeName, states.quantity as quantity, statesPersons.fullName as statePersonName,';
		array_push ($q, ' debsGroups.shortName as debsGroupShortName, debsGroups.fullName as debsGroupFullName ');
		array_push ($q, ' FROM [e10pro_property_property] as property');
		array_push ($q, ' LEFT JOIN e10pro_property_types as types ON property.propertyType = types.ndx');
		array_push ($q, ' LEFT JOIN e10pro_property_states as states ON property.ndx = states.property');
		array_push ($q, ' LEFT JOIN e10_persons_persons as statesPersons ON states.person = statesPersons.ndx');
		array_push ($q, ' LEFT JOIN e10doc_debs_groups as debsGroups ON property.debsGroup = debsGroups.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' property.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR property.propertyId LIKE %s', $fts.'%');
			array_push ($q, ' OR statesPersons.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR EXISTS (SELECT ndx FROM e10_base_properties WHERE property.ndx = e10_base_properties.recid AND valueString LIKE %s AND tableid = %s)',
					'%'.$fts.'%', 'e10pro.property.property');
			array_push ($q, ')');
		}

		if ($this->queryParam('comboSrcTableId') === 'e10pro.vlb.logbook')
			array_push ($q, ' AND [useVehicleLogbook] != ', 0);

		$this->defaultQuery($q);

		// -- special queries
		$qv = $this->queryValues ();

		// -- begin / end date
		if (isset ($qv['period']['beginDate']) && $qv['period']['beginDate'] != '0')
			utils::calendarMonthQuery2('[property].[dateStart]', $q, $qv['period']['beginDate']);
		if (isset ($qv['period']['endDate']) && $qv['period']['endDate'] != '0')
			utils::calendarMonthQuery2('[property].[dateEnd]',  $q, $qv['period']['endDate']);

		if (isset ($qv['propertyTypes']))
			array_push ($q, " AND [propertyType] IN %in", array_keys($qv['propertyTypes']));

		if (isset ($qv['propertyGroups']))
		{
			$types = [];
			$g = $this->groups;
			$groupsNdxs = array_keys($qv['propertyGroups']);
			foreach ($groupsNdxs as $groupNdx)
				$types += $this->groups[$groupNdx]['types'];

			array_push($q, " AND [propertyType] IN %in", $types);
		}

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE property.ndx = recid AND tableId = %s', 'e10pro.property.property');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		// -- person
		if (isset($qv['person']['']) && $qv['person'][''] != 0)
		{
			array_push ($q, ' AND EXISTS (SELECT property FROM e10pro_property_states WHERE property = property.ndx AND person = %i', $qv['person'][''], ')');
		}

		// -- place
		if (isset($qv['place']['']) && $qv['place'][''] != 0)
		{
			array_push ($q, ' AND EXISTS (SELECT property FROM e10pro_property_states WHERE property = property.ndx AND place = %i', $qv['place'][''], ')');
		}

		// -- bottom tab
		if ($bottomQuery === 'tangible')
			array_push ($q, ' AND property.[propertyCategory] = 1');
		if ($bottomQuery === 'intangible')
			array_push ($q, ' AND property.[propertyCategory] = 2');
		if ($bottomQuery === 'landed')
			array_push ($q, ' AND property.[propertyCategory] = 3');
		if ($bottomQuery === 'deps')
			array_push ($q, ' AND property.[propertyCategory] != 0');

		// -- active
		if ($mainQuery === 'active' || $mainQuery == 'activeById' || $mainQuery == '')
		{
			if ($fts != '')
				array_push ($q, ' AND property.[docStateMain] IN (0, 2, 5)');
			else
				array_push ($q, ' AND property.[docStateMain] < 4');
		}

		if ($mainQuery === 'inuse')
			array_push ($q, ' AND property.[docStateMain] = 2');
		if ($mainQuery === 'discarded')
			array_push ($q, ' AND property.[docStateMain] = 5');
		if ($mainQuery === 'trash')
			array_push ($q, ' AND property.[docStateMain] = 4');

		if ($this->viewId === 'debs')
		{
			if ($mainQuery === 'activeById')
				array_push($q, ' ORDER BY property.[docStateMain], property.[propertyId], property.[ndx]');
			elseif ($mainQuery === 'all')
				array_push($q, ' ORDER BY property.[propertyId], property.[ndx]');
			else
				array_push($q, ' ORDER BY property.[docStateMain], property.[fullName], property.[ndx]');
		}
		else
		{
			if ($mainQuery === 'activeById')
				array_push($q, ' ORDER BY property.[docStateMain], property.[propertyId], property.[ndx]');
			elseif ($mainQuery === 'all')
				array_push($q, ' ORDER BY property.[fullName], property.[ndx]');
			else
				array_push($q, ' ORDER BY property.[docStateMain], property.[fullName], property.[ndx]');
		}

		array_push($q, $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);

	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item ['fullName'];

		$listItem ['t2'] = [];
		if ($item ['propertyId'] != '')
			$listItem ['t2'][] = ['text' => $item ['propertyId'], 'class' => 'label label-info'];
		if ($item ['foreign'])
			$listItem ['t2'][] = ['text' => 'Cizí', 'class' => 'label label-primary'];
		if ($item['typeName'])
			$listItem ['t2'][] = ['text' => $item ['typeName'], 'class' => 'label label-default', 'icon' => 'icon-list'];
		if ($item['debsGroupShortName'])
			$listItem ['t2'][] = ['text' => $item ['debsGroupShortName'], 'class' => 'label label-default', 'icon' => 'icon-folder-open-o'];

		if ($item['propertyKind'] === TableProperty::pkStock)
			$listItem ['i2'] = utils::nf ($item['quantity']).' ks';
		else
		if ($item['propertyKind'] === TableProperty::pkSingle)
		{
			if ($item['statePersonName'])
				$listItem ['i2'] = ['icon' => 'icon-user', 'text' => $item['statePersonName']];
		}
		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			$item ['t3'] = [];
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t3'] = array_merge ($item ['t3'], $clsfGroup);
		}
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- persons & places
		$q[] = 'SELECT DISTINCT states.person AS person, persons.fullName AS personName';
		array_push ($q, ' FROM e10pro_property_states AS states');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON states.person = persons.ndx');
		array_push ($q, ' WHERE states.person != %i', 0, ' AND states.quantity != %i', 0);
		$persons = [];
		$persons[0] = 'Všichni';

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
			$persons[$r['person']] = $r['personName'];

		$paramsPP = new GlobalParams ($panel->table->app());
		$paramsPP->addParam ('switch', 'query.person', ['title' => 'Osoba', 'switch' => $persons]);

		$q = [];
		$q [] = 'SELECT places.* FROM [e10_base_places] AS places';
		array_push ($q, ' WHERE places.docStateMain <= %i', 2);
		array_push ($q,' ORDER BY id, shortName');
		$places = [];
		$places[0] = 'Vše';

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
			$places[$r['ndx']] = (($r['placeParent']) ? ' - ' : ''). $r['shortName'];

		$paramsPP->addParam ('switch', 'query.place', ['title' => 'Místo', 'switch' => $places]);


		$paramsPP->detectValues();
		$qry[] = ['id' => 'paramPP', 'style' => 'params', 'title' => 'Osoby a místa', 'params' => $paramsPP, 'class' => 'switches'];

		// start / end date
		$paramsDates = new GlobalParams ($panel->table->app());
		$periodFlags = ['enableAll', 'quarters', 'halfs', 'years'];
		$paramsDates->addParam ('calendarMonth', 'query.period.beginDate', ['flags' => $periodFlags, 'title' => 'Pořízení', 'years' => $this->table->propertyYears()]);
		$paramsDates->addParam ('calendarMonth', 'query.period.endDate', ['flags' => $periodFlags, 'title' => 'Vyřazení', 'years' => $this->table->propertyYears('dateEnd')]);
		$qry[] = ['id' => 'paramDates', 'style' => 'params', 'title' => 'Období', 'params' => $paramsDates];

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = ['style' => 'params', 'title' => $cg['name'], 'params' => $params];
		}

		// -- groups
		$propertyGroups = $this->db()->query ('SELECT ndx, shortName FROM e10pro_property_groups WHERE docStateMain != 4')->fetchPairs ('ndx', 'shortName');
		$this->qryPanelAddCheckBoxes($panel, $qry, $propertyGroups, 'propertyGroups', 'Skupiny majetku');

		// -- types
		$propertyTypes = $this->db()->query ('SELECT ndx, shortName FROM e10pro_property_types WHERE docStateMain != 4')->fetchPairs ('ndx', 'shortName');
		$this->qryPanelAddCheckBoxes($panel, $qry, $propertyTypes, 'propertyTypes', 'Typy majetku');


		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
} // class ViewProperty


/**
 * Class ViewDetailProperty
 * @package E10Pro\Property
 */
class ViewDetailProperty extends TableViewDetail
{
	public function createDetailContent ()
	{
		$card = new \e10pro\property\DocumentCardProperty($this->app());
		if ($this->detailId === 'debs')
			$card->showDeprecations = TRUE;
		$card->setDocument($this->table(), $this->item);
		$card->createContent();
		foreach ($card->content['body'] as $cp)
			$this->addContent($cp);
	}

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();

		if ($this->item['propertyKind'] === 1)
		{
			$addParams = '__rowType=70&__property='.$this->item['ndx'];
			$toolbar [] = ['type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.property.operations',
										 'data-addparams' => $addParams, 'text' => 'Výdej'];

			$addParams = '__rowType=71&__property='.$this->item['ndx'];
			$toolbar [] = ['type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.property.operations',
										 'data-addparams' => $addParams, 'text' => 'Příjem'];
		}

		return $toolbar;
	}
}


/**
 * Class ViewDetailPropertyAccounting
 * @package e10pro\property
 */
class ViewDetailPropertyAccounting extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer('e10doc.debs.journal', 'e10doc.debs.ViewJournalDoc', ['property' => $this->item['ndx']]);
	}
}


/**
 * Class FormProperty
 * @package E10Pro\Property
 */

class FormProperty extends TableForm
{
	public function renderForm ()
	{
		$useDeps = $this->table->useDepreciations($this->recData);

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);
		$this->openForm ();

		$properties = $this->addList ('properties', '', TableForm::loAddToFormLayout|TableForm::loWidgetParts);

		$tabs ['tabs'][] = array ('text' => 'Základní', 'icon' => 'system/formHeader');
		$tabs ['tabs'][] = ['text' => 'Příslušen- ství', 'icon' => 'formAccessories'];
		forEach ($properties ['memoInputs'] as $mi)
			$tabs ['tabs'][] = array ('text' => $mi ['text'], 'icon' => $mi ['icon']);
		if ($this->recData['foreign'] == FALSE && $useDeps)
		{
			$tabs ['tabs'][] = ['text' => 'Odpisy', 'icon' => 'formDepreciations'];
			//$tabs ['tabs'][] = ['text' => 'Účtování', 'icon' => 'x-stamp'];
		}
		$tabs ['tabs'][] = array ('text' => 'Poznámka', 'icon' => 'system/formNote');
		$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');

		$this->openTabs ($tabs, TRUE);
			$this->openTab ();
				$this->addColumnInput ("propertyType");
				$this->addColumnInput ("fullName");
				$this->addColumnInput ("propertyId");

				$this->layoutOpen (TableForm::ltHorizontal);
					$this->layoutOpen (TableForm::ltForm);
						$this->addColumnInput ('propertyCategory');
						$this->addColumnInput ('debsGroup');
						$this->addColumnInput ("propertyKind");
						if (!$useDeps)
							$this->addColumnInput ("foreign");
					$this->layoutClose('width50');
					$this->layoutOpen (TableForm::ltForm);
						$this->addColumnInput ('useVehicleLogbook');

						$this->openRow();
							$this->addColumnInput ("dateStart");
							$this->addColumnInput ('priceIn');
						$this->closeRow();
						$this->addColumnInput ("dateEnd");
					$this->layoutClose('width50');
				$this->layoutClose();
				if ($this->recData['foreign'] == TRUE && !$useDeps)
					$this->addColumnInput ("owner");

				$this->addSeparator(TableForm::coH2);

				$this->addList ('clsf', '', TableForm::loAddToFormLayout);
				$this->appendCode ($properties ['widgetCode']);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addList('accessory');
			$this->closeTab ();


			forEach ($properties ['memoInputs'] as $mi)
			{
				$this->openTab ();
					$this->appendCode ($mi ['widgetCode']);
				$this->closeTab ();
			}

			if ($this->recData['foreign'] == FALSE && $useDeps)
			{
				$this->openTab ();
					$this->addStatic(['text' => 'Daňové odpisy', 'class' => 'h2 padd5']);

					$this->addColumnInput ('depreciationGroup');

					if ($this->recData['depreciationGroup'] === 'X')
						$this->addColumnInput ('taxDepLength');
					else
						$this->addColumnInput ('depreciationType');

					$this->addSeparator(TableForm::coH1);
					$this->addStatic(['text' => 'Účetní odpisy', 'class' => 'h2 padd5']);

					$this->addColumnInput ('accDepType');

					if ($this->recData['accDepType'] === 'AC')
					{
						$this->openRow();
						$this->addColumnInput('accDepLength');
						$this->addColumnInput('accDepLengthUnit');
						$this->closeRow();
					}
				$this->closeTab ();
			}

			$this->openTab (TableForm::ltNone);
				$this->addInputMemo ("note", NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}
} // class FormProperty


/**
 * Class ViewDetailPropertyDepsTax
 * @package E10Pro\Property
 */
class ViewDetailPropertyDepsTax extends TableViewDetail
{
	public function createDetailContent ()
	{
		if ($this->table()->useDepreciations($this->item, FALSE))
		{
			$de = new \e10pro\property\DepreciationsEngine ($this->app());
			$de->init();
			$de->setProperty($this->item['ndx']);
			$de->createTaxDepsPlan(FALSE);

			$c = $de->taxDepsContent();

			$this->addContent ($c);

			$errors = $de->createErrorsContent();
			if ($errors)
				$this->addContent ($errors);
		}
		else
		{
			$this->addContent(array ('type' => 'text', 'subtype' => 'auto', 'text' => 'Tento majetek se neodepisuje.'));
		}
	}
}


/**
 * Class ViewDetailPropertyDepsAcc
 * @package E10Pro\Property
 */
class ViewDetailPropertyDepsAcc extends TableViewDetail
{
	public function createDetailContent ()
	{
		if ($this->table()->useDepreciations($this->item, FALSE))
		{
			$de = new \e10pro\property\DepreciationsEngine ($this->app());
			$de->init();
			$de->setProperty($this->item['ndx']);
			$de->createAccDepsPlan(FALSE);

			$c = $de->accDepsContent();
			$this->addContent ($c);

			$errors = $de->createErrorsContent();
			if ($errors)
				$this->addContent ($errors);
		}
		else
		{
			$this->addContent(array ('type' => 'text', 'subtype' => 'auto', 'text' => 'Tento majetek se neodepisuje.'));
		}
	}
}


/**
 * Class ViewDetailPropertyNonDeps
 * @package e10pro\property
 */
class ViewDetailPropertyNonDeps extends TableViewDetail
{
	public function createDetailContent ()
	{
		$de = new \e10pro\property\DepreciationsEngine ($this->app());
		$de->init();
		$de->setProperty($this->item['ndx']);
		$de->createNonDepsPlan();

		$c = $de->nonDepsContent();
		$this->addContent ($c);
	}
}

/**
 * Class ViewDetailPropertyDeferredTax
 * @package e10pro\property
 */
class ViewDetailPropertyDeferredTax extends TableViewDetail
{
	public function createDetailContent ()
	{
		if ($this->table()->useDepreciations($this->item, FALSE))
		{
			$de = new \e10pro\property\DepreciationsEngine ($this->app());
			$de->init();
			$de->setProperty($this->item['ndx']);
			$de->createTaxDepsPlan(FALSE);
			$de->createAccDepsPlan(FALSE);

			$c = $de->createDeferredTaxContent();
			$c['pane'] = 'e10-pane e10-pane-table';
			$c['main'] = TRUE;
			$c['title'] = ['text' => 'Výpočet odložené daně', 'icon' => 'icon-shield'];

			$this->addContent ($c);
		}
		else
		{
			$this->addContent(array ('type' => 'text', 'subtype' => 'auto', 'text' => 'Tento majetek se neodepisuje.'));
		}
	}
}

/**
 * Class ViewDetailPropertyOperations
 * @package E10Pro\Property
 */

class ViewDetailPropertyOperations extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10pro.property.operations', 'e10pro.property.ViewOperations',
			['property' => $this->item ['ndx']]);
	}
}
