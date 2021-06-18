<?php

namespace E10Doc\Inventory;

use \E10\Application, \E10\DbTable, \E10\TableView, \E10\TableViewGrid, \E10\TableForm, \E10\utils, \e10doc\core\e10utils;

/**
 * TableCheckRows
 *
 */

class TableCheckRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.inventory.checkRows", "e10doc_inventory_checkRows", "Řádky inventur");
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (isset ($recData['_addFromSensor']))
		{
			if ($recData['_addFromSensor']['type'] === 'barcode')
			{
				$sql = 'SELECT * FROM [e10_base_properties] props LEFT JOIN e10_witems_items items ON props.recid = items.ndx where [tableid] = %s AND property = %s AND valueString = %s AND items.docStateMain != 4';

				$witemEan = $this->db()->query ($sql, 'e10.witems.items', 'ean', $recData['_addFromSensor']['value'])->fetch ();
				if ($witemEan)
				{
					$witem = $this->loadItem ($witemEan['recid'], 'e10_witems_items');
					if ($witem)
					{
						$recData['item'] = $witem['ndx'];
						$recData['quantity'] = 1;
						$recData['unit'] = $witem['defaultUnit'];
						unset ($recData['_addFromSensor']);
					}
				}
			}
		}
	}
}


/**
 * ViewCheckRows
 *
 */

class ViewCheckRows extends TableView
{
	var $itemsUnits;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		if ($this->queryParam ('inventoryCheck'))
			$this->addAddParam ('inventoryCheck', $this->queryParam ('inventoryCheck'));

		$this->itemsUnits = $this->app()->cfgItem ('e10.witems.units');
		if ($this->queryParam ('inventoryCheckMainState') == '0')
			$this->classes = array ('addByBarcode');

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['itemFullName'];
		$listItem ['i1'] = array ('text' => '#'.$item['itemid'], 'class' => 'id');
		$listItem ['i2'] = utils::nf ($item['quantity']) . ' ' . $this->itemsUnits[$item['rowUnit']]['shortcut'];

		if ($item['note'] != '')
			$listItem ['t2'] = $item['note'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT [rows].ndx as ndx, [rows].item as item, [rows].[note] as [note], [rows].quantity as quantity,
										[rows].[unit] as rowUnit, items.fullName as itemFullName, items.id as itemid
						FROM e10doc_inventory_checkRows as [rows]
						RIGHT JOIN e10_witems_items as items on (items.ndx = [rows].item) WHERE 1";

		if ($this->onlyOneRec)
			array_push ($q, " AND [rows].[ndx] = %i", $this->onlyOneRec);

		if ($fts != '')
		{
			array_push ($q, " AND items.[fullName] LIKE %s", '%'.$fts.'%');
		}

		array_push ($q, " AND [rows].inventoryCheck = %i", $this->queryParam ('inventoryCheck'));
		array_push ($q, " ORDER BY [rows].ndx" . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows
} // class ViewCheckRows


/*
 * FormCheckRow
 *
 */

class FormCheckRow extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ("item");
			$this->addColumnInput ("quantity");
			$this->addColumnInput ("unit");
			$this->addColumnInput ("note");
		$this->closeForm ();
	}
}

/**
 * ViewCheckRowsStates
 *
 */

class ViewCheckRowsStates extends TableViewGrid
{
	var $itemsUnits;
	var $itemsStates = array();

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
		if ($this->queryParam ('inventoryCheck'))
			$this->addAddParam ('inventoryCheck', $this->queryParam ('inventoryCheck'));

		$this->itemsUnits = $this->app()->cfgItem ('e10.witems.units');

		$g = array (
			'#' => ' #',
			'id' => ' Položka',
			'title' => 'Název',
			'quantity' => ' Inventura',
			'state' => ' Na skladě',
			'diff' => ' Rozdíl',
			'unit' => 'Jedn.'
		);

		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['item'];

		$listItem ['id'] = strval($item['itemid']);
		$listItem ['title'] = $item['itemFullName'];
		$listItem ['quantity'] = utils::nf ($item['quantity']);
		$listItem ['unit'] = $this->itemsUnits[$item['rowUnit']]['shortcut'];
		$listItem ['state'] = utils::nf ($item['state']);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT [rows].item as item, SUM([rows].quantity) as quantity,
										[rows].[unit] as rowUnit, items.fullName as itemFullName, items.id as itemid
						FROM e10doc_inventory_checkRows as [rows]
						RIGHT JOIN e10_witems_items as items on (items.ndx = [rows].item) WHERE 1";

		if ($this->onlyOneRec)
			array_push ($q, " AND [rows].[ndx] = %i", $this->onlyOneRec);

		if ($fts != '')
		{
			array_push ($q, " AND items.[fullName] LIKE %s", '%'.$fts.'%');
		}

		array_push ($q, " AND [rows].inventoryCheck = %i", $this->queryParam ('inventoryCheck'));
		array_push ($q, " GROUP BY item, rowUnit");
		array_push ($q, " ORDER BY itemFullName, [rows].ndx" . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
		$date = $this->queryParam ('dateCheck');
		$fiscalYear = e10utils::todayFiscalYear ($this->app, $date);

		$q = "SELECT SUM(quantity) as quantity, SUM(price) as price, item, unit FROM [e10doc_inventory_journal] WHERE [item] IN %in AND [fiscalYear] = %i AND [date] <= %d GROUP BY item, unit";
		$rows = $this->table->app()->db()->query ($q, $this->pks, $fiscalYear, $date);
		forEach ($rows as $r)
			$this->itemsStates [$r['item']] = array ('quantity' => $r['quantity'], 'price' => $r['price'], 'unit' => $this->itemsUnits[$r['unit']]['shortcut']);
	}

	function decorateRow (&$item)
	{
		if (isset ($this->itemsStates [$item ['pk']]) && $this->itemsStates [$item ['pk']]['unit'] === $item['unit'])
		{
			$item ['state'] = $this->itemsStates [$item ['pk']]['quantity'];
			$item ['diff'] = $item ['quantity'] - $item ['state'];
		}
	}
} // class ViewCheckRowsStates



/**
 * ViewCheckRowsDiffs
 *
 */

class ViewCheckRowsDiffs extends TableViewGrid
{
	var $itemsUnits;
	var $itemsStates = array();

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
		if ($this->queryParam ('inventoryCheck'))
			$this->addAddParam ('inventoryCheck', $this->queryParam ('inventoryCheck'));

		$this->itemsUnits = $this->app()->cfgItem ('e10.witems.units');

		$g = array (
			'#' => ' #',
			'id' => ' Položka',
			'title' => 'Název',
			'checkQuantity' => ' Inventura',
			'invQuantity' => ' Na skladě',
			'diff' => ' Rozdíl',
			'unit' => 'Jedn.',
		);
		$this->setGrid ($g);


		$mq [] = array ('id' => 'equal', 'title' => 'V pořádku', 'side' => 'left');
		$mq [] = array ('id' => 'diff', 'title' => 'Rozdíl', 'side' => 'left');
		$mq [] = array ('id' => 'excess', 'title' => 'Přebývá', 'side' => 'left');
		$mq [] = array ('id' => 'deficit', 'title' => 'Chybí', 'side' => 'left');
		$mq [] = array ('id' => 'notfound', 'title' => 'Nenalezeno', 'side' => 'left');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$this->setMainQueries ($mq);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['item'];

		$listItem ['id'] = strval($item['itemid']);
		$listItem ['title'] = $item['itemFullName'];
		$listItem ['invQuantity'] = utils::nf ($item['invQuantity']);
		$listItem ['unit'] = $this->itemsUnits[$item['itemUnit']]['shortcut'];
		$listItem ['checkQuantity'] = utils::nf ($item['checkQuantity']);
		$listItem ['diff'] = utils::nf ($item['checkQuantity'] - $item['invQuantity']);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$date = $this->queryParam ('dateCheck');
		$fiscalYear = e10utils::todayFiscalYear($this->app, $date);
		$inventoryCheck = $this->queryParam ('inventoryCheck');

		$q [] = 'SELECT items.ndx as item, items.fullname as itemFullName, items.id as itemid, items.defaultUnit as itemUnit,';
		array_push ($q, ' (SELECT sum(quantity) as q1 from e10doc_inventory_journal as journal WHERE [fiscalYear] = %i AND [date] <= %d AND journal.item = items.ndx) as invQuantity,', $fiscalYear, $date);
		array_push ($q, ' (SELECT sum(quantity) as q2 from e10doc_inventory_checkRows as checks WHERE inventoryCheck = %i AND checks.item = items.ndx) as checkQuantity', $inventoryCheck);
		array_push ($q, ' FROM e10_witems_items as items');
		array_push ($q, ' WHERE 1');

		if ($fts != '')
		{
			array_push ($q, ' AND items.[fullName] LIKE %s', '%'.$fts.'%');
		}

		if ($mainQuery === 'equal' || $mainQuery === '')
			array_push ($q, ' HAVING checkQuantity = invQuantity ');
		else
		if ($mainQuery === 'diff')
			array_push ($q, ' HAVING (checkQuantity != invQuantity) OR (invQuantity != 0 AND checkQuantity IS NULL) OR (checkQuantity != 0 AND invQuantity IS NULL)');
		else
		if ($mainQuery === 'excess')
			array_push ($q, ' HAVING (checkQuantity > invQuantity) OR (checkQuantity > 0 AND invQuantity IS NULL)');
		else
		if ($mainQuery === 'deficit')
			array_push ($q, ' HAVING (checkQuantity < invQuantity) OR (invQuantity != 0 AND checkQuantity IS NULL)');
		else
		if ($mainQuery === 'notfound')
			array_push ($q, ' HAVING checkQuantity IS NULL AND invQuantity != 0');

		array_push ($q, ' ORDER BY itemFullName, item');

		array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows

} // class ViewCheckRowsDiffs
