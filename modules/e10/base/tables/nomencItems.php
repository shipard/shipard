<?php

namespace e10\base;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \E10\utils, \E10\TableView, \E10\TableViewGrid, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableNomencItems
 * @package e10\base
 */
class TableNomencItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.nomencItems', 'e10_base_nomencItems', 'Položky nomenklatury');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$prefixParts = explode ('.', $inputPrefix);
		if (isset($prefixParts[0]) && $prefixParts[0] === 'subColumns')
			$pk = isset($form->subColumnsData[$prefixParts[1]][$srcColumnId]) ? intval($form->subColumnsData[$prefixParts[1]][$srcColumnId]) : 0;
		else
			$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$q[] = 'SELECT [fullName], [itemId] ';
		array_push($q, ' FROM [' . $this->sqlName () . '] WHERE [ndx] = %i', intval ($pk));

		$refRec = $this->app()->db()->query ($q)->fetch ();
		$refTitle = ['prefix' => '#'.$refRec ['itemId'], 'text' => $refRec ['fullName']];

		return $refTitle;
	}
}


/**
 * Class ViewNomencItems
 * @package e10\base
 */
class ViewNomencItems extends TableViewGrid
{
	public function init ()
	{
		$this->gridEditable = TRUE;

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->addAddParam ('nomencType', $this->queryParam ('nomencType'));

		$this->setMainQueries ();

		$g = [
				'itemId' => 'Kód',
				'shortName' => 'Název',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['itemId'] = $item['itemId'];
		$listItem ['shortName'] = $item['shortName'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * from [e10_base_nomencItems] WHERE 1';

		// -- type
		$nomencType = $this->queryParam ('nomencType');
		if ($nomencType)
			array_push($q, ' AND [nomencType] = %i', $nomencType);

		$this->qryDefault ($q);

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND ',
					'([fullName] LIKE %s', '%'.$fts.'%',
					' OR [shortName] LIKE %s', '%'.$fts.'%',
					' OR [itemId] LIKE %s', '%'.$fts.'%',
					')'
			);
		}
		$this->queryMain ($q, '', ['[order]', '[itemId]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	function qryDefault (&$q) {}
}


/**
 * Class ViewNomencItemsCombo
 * @package e10\base
 */
class ViewNomencItemsCombo extends TableView
{
	public function init ()
	{
		parent::init();

		if ($this->queryParam ('nomencType'))
			$this->addAddParam ('nomencType', $this->queryParam ('nomencType'));

		$nomencType = $this->queryNomencTypeValue();
		if ($nomencType)
		{
			$ntRec = $this->app()->loadItem($nomencType, 'e10.base.nomencTypes');

			$bt = [];
			$bt [] = ['id' => strval($nomencType), 'title' => $ntRec['shortName'], 'active' => 1];
			$bt [] = ['id' => '0', 'title' => 'Vše', 'active' => 1];

			$this->setBottomTabs ($bt);
		}

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['itemId'];
		$listItem ['t2'] = $item['shortName'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$btId = intval($this->bottomTabId ());

		$q [] = 'SELECT * from [e10_base_nomencItems] WHERE 1';

		// -- type
		if ($btId)
		{
			$nomencType = $this->queryNomencTypeValue();
			if ($nomencType)
				array_push($q, ' AND [nomencType] = %i', $nomencType);
		}

		$this->qryDefault ($q);

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND ',
					'([fullName] LIKE %s', '%'.$fts.'%',
					' OR [shortName] LIKE %s', '%'.$fts.'%',
					' OR [itemId] LIKE %s', '%'.$fts.'%',
					')'
			);
		}
		$this->queryMain ($q, '', ['[order]', '[itemId]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	function qryDefault (&$q) {}

	function queryNomencTypeValue ()
	{
		$nomencType = intval($this->queryParam ('nomencType'));
		if (!$nomencType)
		{
			$crd = $this->queryParam('comboRecData');
			if (isset($crd['nomencType']))
				$nomencType = $crd['nomencType'];
		}
		return $nomencType;
	}
}

/**
 * Class ViewDetailNomencItem
 * @package e10\base
 */
class ViewDetailNomencItem extends TableViewDetail
{
}


/**
 * Class FormNomencItem
 * @package e10\base
 */
class FormNomencItem extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('itemId');
			$this->addColumnInput ('validFrom');
			$this->addColumnInput ('validTo');

			$this->addColumnInput ('nomencType');
			$this->addColumnInput ('ownerItem');
			$this->addColumnInput ('order');
			$this->addColumnInput ('level');
		$this->closeForm ();
	}
}

