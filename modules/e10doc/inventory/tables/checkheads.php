<?php

namespace E10Doc\Inventory;
require_once __SHPD_MODULES_DIR__ . 'e10doc/inventory/inventory.php';


use \E10\DbTable, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\FormReport, \E10\utils, E10Doc\Core\e10utils;

/**
 * TableCheckHeads
 *
 */

class TableCheckHeads extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.inventory.checkHeads", "e10doc_inventory_checkHeads", "Inventury");
	}
} // class TableCheckHeads


/**
 * ViewCheckHeads
 *
 */

class ViewCheckHeads extends TableView
{
	public function init ()
	{
		parent::init();

		$mq [] = array ('id' => 'active', 'title' => 'Rozpracované', 'side' => 'left');
		$mq [] = array ('id' => 'done', 'title' => 'Hotové', 'side' => 'left');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['docNumber'];
		$listItem ['i1'] = utils::datef ($item['dateCheck']);
		$listItem ['t2'] = $item['subject'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10doc_inventory_checkHeads] as heads WHERE 1';

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND heads.[subject] LIKE %s", '%'.$fts.'%');
		}

		// -- aktuální
		if ($mainQuery === 'active' || $mainQuery === '')
			array_push ($q, " AND heads.[docStateMain] = 0");

		// done
		if ($mainQuery == 'done')
			array_push ($q, " AND heads.[docStateMain] = 2");

		// koš
		if ($mainQuery == 'trash')
			array_push ($q, " AND heads.[docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [ndx]' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY heads.[docStateMain], [ndx]' . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows
} // class ViewCheckHeads


/**
 * ViewDetailCheckHead
 *
 */

class ViewDetailCheckHead extends TableViewDetail
{
	public function createDetailContent ()
	{
		$checkDocs = $this->linkedDocuments ();

		$h = array ('#' => '#', 'date' => 'Datum', 'docTypeName' => 'DD', 'dn' => ' Doklad', 'title' => 'Text', 'invPrice' => '+Skl. cena');
		$this->addContent (array ('type' => 'table', 'title' => 'Opravné doklady', 'header' => $h, 'table' => $checkDocs));
	}

	public function linkedDocuments ()
	{
		$docs = array ();
		$docLinkId = "INVCHECK;{$this->item['ndx']};";
		$docTypes = $this->app()->cfgItem ('e10.docs.types');

		$q = 'SELECT *, (SELECT SUM(inv.price) FROM e10doc_inventory_journal as inv WHERE  inv.docHead = heads.ndx) as invPrice FROM [e10doc_core_heads] AS heads WHERE [linkId] = %s';
		$existedDocs = $this->db()->query ($q, $docLinkId);
		foreach ($existedDocs as $r)
		{
			$docType = $docTypes [$r['docType']];
			$docStates = $this->table->documentStates ($r);
			$docStateClass = $this->table->getDocumentStateInfo ($docStates, $r, 'styleClass');

			$d = array ('dn' => array ('text' => $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['ndx'], 'icon' => $docType ['icon']),
									'date' => $r ['dateAccounting'], 'docTypeName' => $docType ['shortcut'],
									'title' => $r['title'], 'invPrice' => $r['invPrice'],
									'_options' => array ('cellClasses' => array('dn' => $docStateClass)));

			$docs[] = $d;
		}

		return $docs;
	}

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();

		$toolbar [] = array ('type' => 'action', 'action' => 'addwizard', 'table' => 'e10.persons.persons',
												 'text' => 'Příjemky', 'data-class' => 'e10doc.inventory.CheckDocsWizardIn', 'icon' => 'appIcon-e10-docs-stock-in');
		$toolbar [] = array ('type' => 'action', 'action' => 'addwizard', 'table' => 'e10.persons.persons',
			'text' => 'Výdejky', 'data-class' => 'e10doc.inventory.CheckDocsWizardOut', 'icon' => 'appIcon-e10-docs-stock-out');

		return $toolbar;
	} // createToolbar
}


/**
 * ViewDetailCheckHeadItems
 *
 */

class ViewDetailCheckHeadItems extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10doc.inventory.checkRows', 'e10doc.inventory.ViewCheckRows',
			array ('inventoryCheck' => $this->item ['ndx'], 'inventoryCheckMainState' => $this->item ['docStateMain']));
	}
}


/**
 * ViewDetailCheckHeadStates
 *
 */

class ViewDetailCheckHeadStates extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10doc.inventory.checkRows', 'e10doc.inventory.ViewCheckRowsStates',
			array ('inventoryCheck' => $this->item ['ndx'], 'dateCheck' => $this->item ['dateCheck']->format('Y-m-d')));
	}
}


/**
 * ViewDetailCheckHeadDiffs
 *
 */

class ViewDetailCheckHeadDiffs extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10doc.inventory.checkRows', 'e10doc.inventory.ViewCheckRowsDiffs',
			array ('inventoryCheck' => $this->item ['ndx'], 'dateCheck' => $this->item ['dateCheck']->format('Y-m-d')));
	}
}


/*
 * FormCheckHead
 *
 */

class FormCheckHead extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ("subject");
			$this->addColumnInput ("dateCheck");
			$this->addColumnInput ("docNumber");
			$this->addColumnInput ("warehouse");
		$this->closeForm ();
	}
}

/**
 * CheckReportProtocol
 *
 */

class CheckReportProtocol extends FormReport
{
	var $tableCheckRows;
	var $itemsUnits;

	function init ()
	{
		$this->reportId = 'e10doc.inventory.check-protocol';
		$this->reportTemplate = 'e10doc.inventory.check-protocol';
	}

	public function loadData ()
	{
		parent::loadData();

		$this->tableCheckRows = $this->app->table ('e10doc.inventory.checkRows');
		$this->itemsUnits = $this->app->cfgItem ('e10.witems.units');

		$today = new \DateTime();
		$this->data ['today'] = utils::datef ($today, '%d, %T');
		$this->data ['warehouse'] = $this->table->loadItem ($this->recData['warehouse'], 'e10doc_base_warehouses');
		$this->data ['rowsAll'] = $this->loadRows ();
	}

	public function loadRows ()
	{
		$q [] = "SELECT [rows].item as item, SUM([rows].quantity) as checkQuantity,
										[rows].[unit] as rowUnit, items.fullName as itemFullName, items.id as itemid
						FROM e10doc_inventory_checkRows as [rows]
						RIGHT JOIN e10_witems_items as items on (items.ndx = [rows].item) WHERE 1";
		array_push ($q, " AND [rows].inventoryCheck = %i", $this->recData['ndx']);
		array_push ($q, " GROUP BY item, rowUnit");
		array_push ($q, " ORDER BY itemFullName, [rows].ndx");

		$thisRows = array ();
		$rownum = 1;
		$rows = $this->app->db()->query($q);
		forEach ($rows as $r)
		{
			$r ['print'] = $this->getPrintValues ($this->tableCheckRows, $r);
			$r ['rownum'] = $rownum++;
			$r ['unit'] = $this->itemsUnits[$r['rowUnit']]['shortcut'];

			$thisRows[] = $r->toArray();
		}

		return $thisRows;
	}
} // class CheckReportProtocol



/**
 * CheckReportDiffs
 *
 */

class CheckReportDiffs extends FormReport
{
	var $tableCheckRows;
	var $itemsUnits;

	function init ()
	{
		$this->reportId = 'e10doc.inventory.check-diffs';
		$this->reportTemplate = 'e10doc.inventory.check-diffs';
	}

	public function loadData ()
	{
		parent::loadData();

		$this->tableCheckRows = $this->app->table ('e10doc.inventory.checkRows');
		$this->itemsUnits = $this->app->cfgItem ('e10.witems.units');

		$today = new \DateTime();
		$this->data ['today'] = utils::datef ($today, '%d, %T');
		$this->data ['warehouse'] = $this->table->loadItem ($this->recData['warehouse'], 'e10doc_base_warehouses');
		$this->data ['rowsAll'] = $this->loadRows ('diff');
	}

	public function loadRows ($mainQuery)
	{
		$date = $this->recData['dateCheck']->format('Y-m-d');
		$fiscalYear = e10utils::todayFiscalYear($this->app, $date);
		$inventoryCheck = $this->recData['ndx'];

		$q [] = 'SELECT items.ndx as item, items.fullname as itemFullName, items.id as itemid, items.defaultUnit as itemUnit,';
		array_push ($q, ' (SELECT sum(quantity) as q1 from e10doc_inventory_journal as journal WHERE [fiscalYear] = %i AND [date] <= %d AND journal.item = items.ndx) as invQuantity,', $fiscalYear, $date);
		array_push ($q, ' (SELECT sum(quantity) as q2 from e10doc_inventory_checkRows as checks WHERE inventoryCheck = %i AND checks.item = items.ndx) as checkQuantity', $inventoryCheck);
		array_push ($q, ' FROM e10_witems_items as items');

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

		$thisRows = array ();
		$rownum = 1;
		$rows = $this->app->db()->query($q);
		forEach ($rows as $r)
		{
			$r ['print'] = $this->getPrintValues ($this->tableCheckRows, $r);
			$r ['rownum'] = $rownum++;
			$r ['unit'] = $this->itemsUnits[$r['itemUnit']]['shortcut'];
			$r ['diff'] = $r['checkQuantity'] - $r['invQuantity'];

			$thisRows[] = $r->toArray();
		}

		return $thisRows;
	}
} // class CheckReportDiffs
