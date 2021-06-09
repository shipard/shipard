<?php

namespace E10Doc\Base;
use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TablePlaces
 * @package E10Doc\Base
 */
class TablePlaces extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.base.places", "e10doc_base_places", "Místa");
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
		if ($this->useBookingCapacity($recData) && !$recData['bookingCapacity'])
			$recData['bookingCapacity'] = 1;
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id'] . ' / ' .$recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		$image = \E10\Base\getAttachmentDefaultImage ($this->app(), $this->tableId(), $recData ['ndx'], TRUE);
		if (isset ($image ['smallImage']))
			$hdr ['image'] = $image ['smallImage'];

		return $hdr;
	}

	public function useBookingCapacity ($recData)
	{
		if (!isset ($recData['bookingType']))
			return FALSE;
		$bookingType = $this->app()->cfgItem ('e10pro.bookingTypes.'.$recData['bookingType'], FALSE);
		if (!$bookingType)
			return FALSE;
		return $bookingType['uc'];
	}

	public function loadTree ()
	{
		$places = [];

		$this->loadTreePart (0, $places);

		return $places;
	}

	public function loadTreePart ($parentNdx, &$dst)
	{
		$q [] = 'SELECT places.* FROM [e10doc_base_places] AS places';
		array_push ($q, ' WHERE places.placeParent = %i', $parentNdx);
		array_push ($q,' ORDER BY id, shortName');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = ['ndx' => $r['ndx'], 'title' => $r['shortName'], 'id' => $r['id'], 'places' => []];
			$this->loadTreePart ($r['ndx'], $item['places']);
			$dst[] = $item;
		}
	}

	public function loadParentsPlaces ($parentNdx, &$dst)
	{
		if (!in_array($parentNdx, $dst))
			$dst[] = $parentNdx;

		$q [] = 'SELECT places.* FROM [e10doc_base_places] AS places';
		array_push ($q, ' WHERE places.placeParent = %i', $parentNdx);
		array_push ($q,' ORDER BY id, shortName');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if (!in_array($r['ndx'], $dst))
				$dst[] = $r['ndx'];

			$this->loadParentsPlaces ($r['ndx'], $dst);
		}
	}
}


/**
 * Class ViewPlaces
 * @package E10Doc\Base
 */
class ViewPlaces extends TableView
{
	var $bookingTypes;
	var $defaultType = FALSE;
	/** @var \e10\persons\TableAddress */
	var $tableAddress;
	var $placesAddress;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->tableAddress = $this->app()->table('e10.persons.address');
		$this->setMainQueries ();

		$this->bookingTypes = $this->app()->cfgItem ('e10pro.bookingTypes', []);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['shortName'];
		if (isset ($item['parentId']) && $item['parentId'])
			$listItem ['t2'] = $item['parentId'] . ' / ' . $item['id'];
		else
			$listItem ['t2'] = $item['id'];

		if (isset ($item['bookingType']) && $item['bookingType'])
		{
			$listItem ['i2'] = $this->bookingTypes[$item['bookingType']]['sn'];
			if ($this->bookingTypes[$item['bookingType']]['uc'])
				$listItem ['i2'] .= ' ('. $item['bookingCapacity'] . ')';
		}

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT places.*, parent.id as parentId from [e10doc_base_places] AS places';
		$q [] = ' LEFT JOIN e10doc_base_places AS parent ON places.placeParent = parent.ndx';
		array_push ($q, ' WHERE 1');

		$this->defaultQuery($q);

		if ($this->defaultType !== FALSE)
			array_push ($q, ' AND places.[placeType] = %s', $this->defaultType);

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND (places.[fullName] LIKE %s OR places.[shortName] LIKE %s OR places.[id] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%', $fts.'%');

		$this->queryMain ($q, 'places.', ['places.[id]', 'places.[fullName]']);
		$this->runQuery ($q);
	}

	function decorateRow (&$item)
	{
		if (isset ($this->placesAddress [$item ['pk']]))
		{
			$item ['i2'] = $this->placesAddress [$item ['pk']][0];
		}
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->placesAddress = $this->tableAddress->loadAddresses($this->table, $this->pks);
	}
}


/**
 * Class ViewDetailPlace
 * @package E10Doc\Base
 */
class ViewDetailPlace extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class FormPlace
 * @package E10Doc\Base
 */
class FormPlace extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Adresa', 'icon' => 'formAddress'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('placeType');
					$this->addColumnInput ('placeParent');
					$this->addColumnInput ('bookingType');
					if ($this->table->useBookingCapacity($this->recData))
						$this->addColumnInput ('bookingCapacity');
				$this->closeTab();
				$this->openTab ();
					$this->addList('address', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('shortcutId');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.base.places' && $srcColumnId === 'placeParent')
		{
			$cp = [
				'placeType' => $allRecData ['recData']['placeType'],
			];
			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}


/**
 * Class ViewPlacesComboLocalOffices
 * @package E10Doc\Base
 */
class ViewPlacesComboLocalOffices extends ViewPlaces
{
	public function init ()
	{
		$this->defaultType = 'lcloffc';
		parent::init();
	}
}

/**
 * Class ViewPlacesComboRooms
 * @package E10Doc\Base
 */
class ViewPlacesComboRooms extends ViewPlaces
{
	public function init ()
	{
		$this->defaultType = 'room';
		parent::init();
	}
}

/**
 * Class ViewPlacesComboParents
 * @package E10Doc\Base
 */
class ViewPlacesComboParents extends ViewPlaces
{
	public function defaultQuery (&$q)
	{
		if ($this->queryParam('placeType'))
		{
			array_push ($q, ' AND places.[placeType] <> %s ', $this->queryParam('placeType'));
		}
	}
}
