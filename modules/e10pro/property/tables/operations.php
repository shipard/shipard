<?php

namespace E10Pro\Property;

require_once 'property.php';

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableOperations
 * @package E10Pro\Property
 */

class TableOperations extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.property.operations", "e10pro_property_operations", "Pohyby majetku");
	}

	public function checkAfterSave2 (&$recData)
	{
		$propertyTable = new \E10Pro\Property\TableProperty($this->app());
		$propertyTable->updateState($recData['property']);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$rowType = $this->app()->cfgItem ('e10pro.property.operations.'.$recData['rowType']);

		if (!in_array('quantity', $rowType['columns']))
			$recData['quantity'] = 1;

		$recData['quantitySigned'] = $recData['quantity'] * $rowType['sign'];

		$recData['quantityFrom'] = 0;
		$recData['quantityTo'] = 0;

		if ($rowType['sign'] == 1) // IN
		{
			if (isset ($recData['placeFrom']) && $recData['placeFrom'])
				$recData['quantityFrom'] = - $recData['quantity'];

			if (isset ($recData['placeTo']) && $recData['placeTo'])
				$recData['quantityTo'] = $recData['quantity'];
		}
		else
		if ($rowType['sign'] == -1) // OUT
		{
			if (isset ($recData['placeFrom']) && $recData['placeFrom'])
				$recData['quantityFrom'] = - $recData['quantity'];

			if (isset ($recData['placeTo']) && $recData['placeTo'])
				$recData['quantityTo'] = $recData['quantity'];
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		if (!isset($recData['date']))
			$recData['date'] = utils::today();
		if (!isset($recData['quantity']))
			$recData['quantity'] = 1;
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, \E10\TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		if ($columnId === 'rowType')
		{
			if ($form->propertyRecData === FALSE)
				$form->propertyRecData = $this->loadItem($form->recData['property'], 'e10pro_property_property');

			if (!in_array($form->propertyRecData ['propertyKind'], $cfgItem['propertyKinds']))
				return FALSE;

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}
}


/**
 * Class ViewOperations
 * @package E10Pro\Property
 */

class ViewOperations extends TableView
{
	var $rowTypes, $centres;

	public function init ()
	{
		parent::init();

		$this->rowTypes = $this->table->app()->cfgItem ('e10pro.property.operations');
		$this->centres = $this->table->app()->cfgItem ('e10doc.centres');

		if ($this->queryParam ('property'))
			$this->addAddParam ('property', $this->queryParam ('property'));

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'done', 'title' => 'Odepsáno');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT operations.*, persons.fullName as personName, placesFrom.fullName as placeFromName, placesTo.fullName as placeToName FROM [e10pro_property_operations] AS operations';
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON operations.person = persons.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS placesFrom ON operations.placeFrom = placesFrom.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS placesTo ON operations.placeTo = placesTo.ndx');
		array_push($q, ' WHERE 1');

		array_push ($q, " AND property = %i", $this->queryParam ('property'));

		// -- active
		if ($mainQuery === 'active' || $mainQuery == '')
			array_push ($q, " AND operations.[docStateMain] < 4");

		if ($mainQuery === 'done')
			array_push ($q, " AND operations.[docStateMain] = 2");
		if ($mainQuery === 'trash')
			array_push ($q, " AND operations.[docStateMain] = 4");

		if ($mainQuery === 'all')
			array_push ($q, ' ORDER BY [date] DESC, [ndx] ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY operations.[docStateMain], [date] DESC, [ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$rowType = $this->rowTypes [$item ['rowType']];
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $rowType['title'];
		$listItem ['i1'] = utils::datef ($item ['date']);
		$listItem ['i2'] = utils::nf($item['quantity']).' ks';

		$props = [];
		if ($item['personName'])
			$props[] = ['icon' => 'icon-user', 'text' => $item['personName']];

		if ($item['centre'])
			$props[] = ['icon' => 'icon-bullseye', 'text' => $this->centres[$item['centre']]['fullName']];

		if ($item['placeFromName'] && $item['placeToName'])
			$props[] = ['icon' => 'icon-map-marker', 'text' => $item['placeFromName'].' ➤ '.$item['placeToName']];
		else
		if ($item['placeFromName'])
			$props[] = ['icon' => 'icon-map-marker', 'text' => $item['placeFromName'].'✖︎'];
		else
		if ($item['placeToName'])
			$props[] = ['icon' => 'icon-map-marker', 'text' => '➤'.$item['placeToName']];

		$listItem ['t2'] = $props;

		if (isset($rowType['qtype']))
			$listItem ['i2'] = utils::nf ($item ['quantity']).' ks';

		if ($item['note'] != '')
			$listItem ['t3'] = $item['note'];

		return $listItem;
	}
} // class ViewOperations


/**
 * Class FormOperation
 * @package E10Pro\Property
 */

class FormOperation extends TableForm
{
	public $propertyRecData = FALSE;

	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
//		$this->setFlag ('maximize', 1);
		$this->openForm ();

		$rowType = $this->table->app()->cfgItem ('e10pro.property.operations.'.$this->recData['rowType']);

		$this->addColumnInput ('date');
		$this->addColumnInput ('rowType');

		if (in_array('person', $rowType['columns']))
			$this->addColumnInput ('person');

		if (in_array('centre', $rowType['columns']))
			$this->addColumnInput ('centre');

		if (in_array('quantity', $rowType['columns']))
			$this->addColumnInput ('quantity');

		if (in_array('placeFrom', $rowType['columns']))
			$this->addColumnInput ('placeFrom');

		if (in_array('placeTo', $rowType['columns']))
			$this->addColumnInput ('placeTo');

		$this->addColumnInput ('note');


		$this->closeForm ();
	}
}
