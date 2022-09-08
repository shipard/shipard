<?php

namespace E10Doc\Debs;
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/debs/debs.php';


use \E10\Application, \E10\utils, E10Doc\Core\e10utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \Shipard\Viewer\TableViewPanel;
use \E10\DbTable;

class TableJournal extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.debs.journal", "e10doc_debs_journal", "Účetní deník");
	}


	public function doIt (&$recData)
	{
		$this->db()->query ("DELETE FROM [e10doc_debs_journal] WHERE [document] = %i", $recData['ndx']);

		if ($recData ['docState'] != 4000)
			return;

		$docAccEngine = new docAccounting ($this->app());
		$docAccEngine->setDocument ($recData);
		$docAccEngine->run();
		$docAccEngine->save();

		if ($docAccEngine->messagess() === FALSE)
			$recData ['docStateAcc'] = 1;
		else
			$recData ['docStateAcc'] = 9;

		$this->db()->query ("UPDATE [e10doc_core_heads] SET docStateAcc = %i WHERE ndx = %i", $recData ['docStateAcc'], $recData['ndx']);
	}
} // class TableAccounts


/*
 * ViewJournalAll
 *
 */

class ViewJournalAll extends \E10\TableViewGrid
{
	var $centres;
	var $docTypes;

	public $fiscalPeriod = FALSE;
	public $accountId = FALSE;

	var $useWorkOrders = FALSE;


	var $sumAccountId = '';
	var $sumAccountRow = NULL;
	var $sortByAccountId = FALSE;

	public function init ()
	{
		if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
			$this->useWorkOrders = TRUE;


		$this->topParams = new \e10doc\core\libs\GlobalParams ($this->table->app());
		$this->topParams->addParam ('fiscalPeriod', 'queryFiscalPeriod', ['colWidth' => 3, 'flags' => ['enableAll', 'quarters', 'halfs', 'years', 'openclose']]);
		$this->topParams->addParam ('float', 'queryAmount', ['title' => 'Částka', 'colWidth' => 1]);
		$this->topParams->addParam ('float', 'queryAmountDiff', ['title' => '+/-', 'colWidth' => 1]);
		$this->topParams->addParam ('switch', 'querySide', ['title' => 'Strana', 'colWidth' => 2, 'switch' => ['-' => 'Obě', 'dr' => 'MD', 'cr' => 'DAL']]);
		$this->topParams->addParam ('string', 'queryAccount', ['title' => 'Účet', 'colWidth' => 1]);
		if ($this->useWorkOrders)
			$this->topParams->addParam ('string', 'queryWorkOrder', ['title' => 'Zakázka', 'colWidth' => 2]);

		if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
			$this->topParams->addParam ('centre', 'queryCentre', ['colWidth' => 2, 'flags' => ['enableAll', 'id']]);

		$this->usePanelRight = 3;

		parent::init();

		$this->centres = $this->table->app()->cfgItem ('e10doc.centres');
		$this->docTypes = $this->table->app()->cfgItem ('e10.docs.types');

		$g = array (
			'dateAccounting' => 'Datum',
			'dt' => 'DD',
			'docNumber' => 'Doklad',
			'accountId' => 'Účet',
			'moneyDr' => '+MD',
			'moneyCr' => '+DAL',
			'symbol1' => ' VS',
			'symbol2' => ' SS',
			'centre' => 'Stř.',
			'projectId' => '_Projekt',
			'wo' => 'Zakázka',
			'personName' => 'Osoba',
			'text' => 'Text'
		);

		if (!$this->table->app()->cfgItem ('options.core.useProjects', 0))
			unset ($g['projectId']);

		if (!$this->useWorkOrders)
			unset ($g['wo']);


		$this->setGrid ($g);

		$this->setInfo('title', 'Účetní deník');
		$this->setInfo('icon', 'system/iconList');
	}

	public function createToolbar (){return array();}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['dateAccounting'] = $item['dateAccounting'];
		$listItem ['accountId'] = $item['accountId'];
		if ($item['side'] === 0)
			$listItem ['moneyDr'] = $item['moneyDr'];
		else
			$listItem ['moneyCr'] = $item['moneyCr'];

		$listItem ['dt'] = $this->docTypes[$item['docType']]['shortcut'];
		$listItem ['centre'] = $this->centres[$item['centre']]['id'];
		$listItem ['docNumber'] = array ('text'=> $item['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $item['document']);

		$listItem ['symbol1'] = $item['symbol1'];
		$listItem ['symbol2'] = $item['symbol2'];
		$listItem ['projectId'] = $item['projectId'];

		if ($this->useWorkOrders)
			$listItem ['wo'] = $item['woDocNumber'];

		$listItem ['personName'] = $item['personName'];

		$listItem ['text'] = $item['text'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$qv = $this->queryValues();

		if (isset ($qv['sortAccountId']))
			$this->sortByAccountId = TRUE;

		$propertyIdParam = $this->queryParam('propertyId');

		$q [] = 'SELECT journal.*, persons.fullName as personName';

		if ($this->useWorkOrders)
			$q [] = ', wo.docNumber AS woDocNumber';

		array_push ($q, ', projects.shortName AS projectId');

		array_push ($q, ' FROM [e10doc_debs_journal] as journal');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON (journal.person = persons.ndx)');


		array_push ($q, 'LEFT JOIN wkf_base_projects AS projects ON journal.project = projects.ndx ');

		if ($this->useWorkOrders)
			array_push ($q, ' LEFT JOIN e10mnf_core_workOrders AS wo ON journal.workOrder = wo.ndx ');

		if ($propertyIdParam !== FALSE && $propertyIdParam !== '')
			array_push ($q, ' LEFT JOIN e10pro_property_property AS property ON journal.property = property.ndx ');

		if (isset ($qv['accountKinds']))
			array_push ($q, ' LEFT JOIN e10doc_debs_accounts AS accounts ON journal.accountId = accounts.id ');

		array_push ($q, ' WHERE 1');
		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND (");
			array_push ($q, "[text] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR ");
			array_push ($q, "[accountId] LIKE %s", $fts.'%');
			array_push ($q, " OR ");
			array_push ($q, "journal.[docNumber] LIKE %s", $fts.'%');
			array_push ($q, " OR ");
			array_push ($q, "journal.[symbol1] LIKE %s", $fts.'%');
			array_push ($q, " OR ");
			array_push ($q, "persons.[fullName] LIKE %s", '%'.$fts.'%');

			array_push ($q, ' OR projects.[shortName] LIKE %s', $fts.'%');

			array_push ($q, ")");
		}

		if ($this->fiscalPeriod !== FALSE)
		{
			//$this->setInfo('param', 'Období', $this->topParamsValues['queryFiscalPeriod']['activeTitle']);
			e10utils::fiscalPeriodQuery($q, $this->fiscalPeriod, 'journal.');
		}
		else
		if (isset ($this->topParamsValues['queryFiscalPeriod']['value']))
		{
			$this->setInfo('param', 'Období', $this->topParamsValues['queryFiscalPeriod']['activeTitle']);
			e10utils::fiscalPeriodQuery($q, $this->topParamsValues['queryFiscalPeriod']['value'], 'journal.');
		}

		if ($this->topParamsValues['queryAmount']['value'] === '' && $this->topParamsValues['querySide']['value'] !== '-')
		{
			$column = '';
			if ($this->topParamsValues['querySide']['value'] === 'dr')
				$column = 'moneyDr';
			else if ($this->topParamsValues['querySide']['value'] === 'cr')
				$column = 'moneyCr';

			if ($column !== '')
				array_push ($q, " AND $column != 0");
		}
		else
		{
			$column = 'money';
			if ($this->topParamsValues['querySide']['value'] === 'dr')
				$column = 'moneyDr';
			else if ($this->topParamsValues['querySide']['value'] === 'cr')
				$column = 'moneyCr';
			$paramValueName = e10utils::amountQuery ($q, $column, $this->topParamsValues['queryAmount']['value'], $this->topParamsValues['queryAmountDiff']['value']);
			if ($paramValueName !== '')
				$this->setInfo('param', 'Částka', $paramValueName);
		}

		if ($this->topParamsValues['querySide']['value'] !== '-')
			$this->setInfo('param', 'Strana', $this->topParamsValues['querySide']['activeTitle']);

		if ($this->accountId !== FALSE)
		{
			array_push ($q, " AND accountId = %s", $this->accountId);
			$this->setInfo('param', 'Účet', $this->accountId);
		}
		else
		if (isset ($this->topParamsValues['queryAccount']['value']) && $this->topParamsValues['queryAccount']['value'] != '')
		{
			array_push ($q, " AND accountId LIKE %s", $this->topParamsValues['queryAccount']['value'].'%');
			$this->setInfo('param', 'Účet', $this->topParamsValues['queryAccount']['value']);
		}

		if (isset ($this->topParamsValues['queryCentre']['value']) && $this->topParamsValues['queryCentre']['value'] != 0)
		{
			array_push ($q, " AND journal.[centre] = %s", $this->topParamsValues['queryCentre']['value']);
			$this->setInfo('param', 'Středisko', $this->topParamsValues['queryCentre']['activeTitle']);
		}

		if (isset ($this->topParamsValues['queryWorkOrder']['value']) && $this->topParamsValues['queryWorkOrder']['value'] != '')
		{
			array_push ($q, " AND wo.docNumber LIKE %s", $this->topParamsValues['queryWorkOrder']['value'].'%');
			$this->setInfo('param', 'Zakázka', $this->topParamsValues['queryWorkOrder']['value']);
		}

		// -- right panel params
		if (isset ($qv['accountKinds']))
			array_push ($q, ' AND accounts.accountKind IN %in', array_keys($qv['accountKinds']));

		$dateFromParam = $this->queryParam('dateAccountingFrom');
		if ($dateFromParam != '')
		{
			$dateFrom = date_create_from_format('d.m.Y', $dateFromParam);
			if ($dateFrom)
				array_push($q, ' AND journal.[dateAccounting] >= %d', $dateFrom);
		}

		$dateToParam = $this->queryParam('dateAccountingTo');
		if ($dateToParam != '')
		{
			$dateTo = date_create_from_format('d.m.Y', $dateToParam);
			if ($dateTo)
				array_push($q, ' AND journal.[dateAccounting] <= %d', $dateTo);
		}

		if (isset ($qv['docsTypes']))
			array_push ($q, ' AND journal.docType IN %in', array_keys($qv['docsTypes']));

		if ($propertyIdParam !== FALSE && $propertyIdParam !== '')
		{
			array_push($q, ' AND property.[propertyId] LIKE %s', '%'.$propertyIdParam.'%');
		}

		// -- order
		if ($this->sortByAccountId)
			array_push ($q, ' ORDER BY [accountId], [dateAccounting], [docNumber], [ndx]');
		else
			array_push ($q, ' ORDER BY [dateAccounting], [docNumber], [ndx]');

		array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');

		$qry = [];

		$chbxAccountKinds = [
			'0' => ['title' => 'Aktiva', 'id' => '0'], '1' => ['title' => 'Pasiva', 'id' => '1'],
			'2' => ['title' => 'Náklady', 'id' => '2'], '3' => ['title' => 'Výnosy', 'id' => '3']
		];
		$paramsAccountKinds = new \E10\Params ($this->app());
		$paramsAccountKinds->addParam ('checkboxes', 'query.accountKinds', ['items' => $chbxAccountKinds]);
		$qry[] = ['style' => 'params', 'title' => 'Povaha účtu', 'params' => $paramsAccountKinds];
		$paramsAccountKinds->detectValues();

		// -- dates
		$paramsDates = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$paramsDates->addParam ('string', 'dateAccountingFrom', ['title' => 'Datum od']);
		$paramsDates->addParam ('string', 'dateAccountingTo', ['title' => 'Datum do']);
		$paramsDates->detectValues();
		$qry[] = ['style' => 'params', 'title' => 'Období', 'params' => $paramsDates];

		// -- docs types
		$chbxDocsTypes =[];
		$docsTypes = $this->app()->cfgItem ('e10.docs.types');
		foreach ($docsTypes as $dtId => $dtDef)
		{
			if (!isset($dtDef['acc']) || $dtDef['acc'] != 1)
				continue;
			$chbxDocsTypes[$dtId] = ['title' => $dtDef['pluralName'], 'id' => $dtId];
		}
		$paramsDocsTypes = new \E10\Params ($this->app());
		$paramsDocsTypes->addParam ('checkboxes', 'query.docsTypes', ['items' => $chbxDocsTypes]);
		$qry[] = ['style' => 'params', 'title' => 'Typy dokladů', 'params' => $paramsDocsTypes];
		$paramsDocsTypes->detectValues();

		// -- property
		$paramsProperty = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$paramsProperty->addParam ('string', 'propertyId', ['title' => 'Inv. č. majetku']);
		$paramsProperty->detectValues();
		$qry[] = ['style' => 'params', 'title' => 'Majetek', 'params' => $paramsProperty];

		// -- other
		$otherOptions = [
			'0' => ['title' => 'Řadit podle čísla účtu', 'id' => '0'],
		];
		$paramsOther = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$paramsOther->addParam ('checkboxes', 'query.sortAccountId', ['items' => $otherOptions]);
		$paramsOther->detectValues();
		$qry[] = ['style' => 'params', 'title' => 'Ostatní', 'params' => $paramsOther];


		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	function sumRow($item)
	{
		if (!$this->sortByAccountId)
			return NULL;

		if ($item === NULL)
			return $this->sumAccountRow;

		$resultRow = NULL;

		if (!$this->sumAccountRow)
		{
			$this->sumAccountRow = [
				'moneyDr' => 0.0, 'moneyCr' => 0.0,
				'dateAccounting' => 'CELKEM '.$item['accountId'],
				'_options' => ['class' => 'subtotal', 'afterSeparator' => 'separator', 'colSpan' => ['dateAccounting' => 4]]
			];
		}

		if ($this->sumAccountId !== '' && $this->sumAccountId !== $item['accountId'])
		{
			$resultRow = $this->sumAccountRow;
			$this->sumAccountRow = [
				'moneyDr' => 0.0, 'moneyCr' => 0.0,
				'dateAccounting' => 'CELKEM '.$item['accountId'],
				'_options' => ['class' => 'subtotal', 'afterSeparator' => 'separator', 'colSpan' => ['dateAccounting' => 4]]
			];
		}

		$this->sumAccountRow['moneyDr'] += $item['moneyDr'];
		$this->sumAccountRow['moneyCr'] += $item['moneyCr'];
		$this->sumAccountId = $item['accountId'];

		return $resultRow;
	}
}


/*
 * ViewJournalDoc
 *
 */

class ViewJournalDoc extends \E10\TableViewGrid
{
	var $centres;
	var $document = FALSE;
	var $workOrder = FALSE;
	var $property = FALSE;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		//$this->enableDetailSearch = TRUE;

		if ($this->queryParam ('document'))
			$this->document = intval($this->queryParam ('document'));

		if ($this->queryParam ('workOrder'))
			$this->workOrder = intval($this->queryParam ('workOrder'));

		if ($this->queryParam ('property'))
			$this->property = intval($this->queryParam ('property'));

		parent::init();

		$this->centres = $this->table->app()->cfgItem ('e10doc.centres');

		$g = [
				'#' => '#',
				'dateAccounting' => 'Datum',
				'docNumber' => 'Doklad',
				'accountId' => 'Účet',
				'moneyDr' => ' MD',
				'moneyCr' => ' DAL',
				//'centre' => 'Středisko',
				'text' => '_Text'
		];

		$this->setGrid ($g);
	}

	public function selectRows ()
	{
		$q [] = 'SELECT * FROM [e10doc_debs_journal] WHERE 1';

		if ($this->document)
			array_push ($q, 'AND [document] = %i ', $this->document);

		if ($this->workOrder)
			array_push ($q, 'AND [workOrder] = %i ', $this->workOrder);

		if ($this->property)
			array_push ($q, 'AND [property] = %i ', $this->property);

		array_push ($q, 'ORDER BY [dateAccounting], [docNumber], [ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['dateAccounting'] = $item['dateAccounting'];

		$listItem ['accountId'] = $item['accountId'];
		if ($item['side'] === 0)
			$listItem ['moneyDr'] = $item['moneyDr'];
		else
			$listItem ['moneyCr'] = $item['moneyCr'];

		$listItem ['centre'] = $this->centres[$item['centre']]['shortName'];
		$listItem ['docNumber'] = array ('text'=> $item['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $item['document']);

		$listItem ['text'] = $item['text'];


		return $listItem;
	}

	public function createToolbar ()
	{
		return array ();
	} // createToolbar
} // class ViewJournalDoc


/**
 * Základní detail řádku deníku
 *
 */

class ViewDetailJournal extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$item = $this->item;

		$hdr ['icon'] = 'x-glasses';
//		$hdr ['info'][] = array ('class' => 'title', 'value' => $item ['id']);
//		$hdr ['info'][] = array ('class' => 'info', 'value' => $item ['fullName']);


		return $this->defaultHedearCode ($hdr);
	}
}


/*
 * FormJournal
 *
 */

class FormJournal extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('maximize', 1);
		//$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ("text");
		$this->closeForm ();
	}

} // class FormJournal
