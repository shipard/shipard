<?php

namespace e10doc\accBal\libs;
use \Shipard\Utils\Utils;
use \Shipard\Viewer\TableViewGrid;
use \Shipard\Viewer\TableViewPanel;


/**
 * class AccBalanceViewer
 */
class AccBalanceViewer extends TableViewGrid
{
	var $docTypes;

	var ?\e10doc\accBal\libs\AccBalanceCfg $balacesCfg = NULL;
	var $balancesParam = NULL;
	var $balanceNdx = 0;
	var $fiscalYear = 0;

	public function init ()
	{
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');

		$this->usePanelLeft = TRUE;
		$this->createLeftPanel();

		if ($this->queryParam ('fiscalYear'))
			$this->fiscalYear = intval($this->queryParam ('fiscalYear'));

		//$this->usePanelRight = 2;

		parent::init();


		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = FALSE;
		//$this->type = 'form';

		//$this->objectSubType = self::vsMain;
		//$this->objectSubType = self::vsDetail;
		$this->usePanelRight = 3;
		$this->detailInPanelRight = 1;
		$this->panelRightClass = 'w30em';

		//$this->linesWidth = 80;
		//$this->setPanels (self::sptQuery);

		$mq [] = ['id' => 'unpaired', 'title' => 'Nevyrovnané'];
		$mq [] = ['id' => 'all', 'title' => 'Vše', 'side' => 'left'];

		$mq [] = ['id' => 'journal', 'title' => 'Deník'];
		$this->setMainQueries ($mq);

		$g = [
			'#' => '#',
			'person' => 'Osoba',
			'doc' => '_Doklad',
			'dateDue' => 'Splatnost',
			'symbol1' => 'VS',
			'symbol2' => 'SS',
      'requestHc' => ' Předpis',
      'paymentHc' => ' Uhrazeno',
			'resultHc' => ' Zůstatek',
			'accountId' => 'Účet',
		];

		$this->setGrid ($g);
	}

	protected function createLeftPanel()
	{
		$enum = [];

    $this->balacesCfg = new \e10doc\accBal\libs\AccBalanceCfg($this->app());
    $this->balacesCfg->setDate($this->documentRecData['dateAccouting'] ?? NULL);
    $this->balacesCfg->loadBalances();

		foreach ($this->balacesCfg->balances as $ndx => $bi)
		{
			if (!$this->balanceNdx)
				$this->balanceNdx = intval($ndx);

			$enum[$ndx] = ['text' => $bi['shortName'], 'class' => ''];
		}

		if (isset($_POST['balance']))
			$this->balanceNdx = intval($_POST['balance']);

		$this->balancesParam = new \Shipard\UI\Core\Params ($this->app);
		$this->balancesParam->addParam('switch', 'balance', ['title' => '', 'defaultValue' => strval($this->balanceNdx), 'switch' => $enum, 'list' => 1]);
		$this->balancesParam->detectValues();
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->balancesParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->balancesParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

    $listItem ['person'] = $item['personFullName'];
		$listItem ['doc'] = $this->docNumber($item);
    $listItem ['symbol1'] = $item['symbol1'];
    $listItem ['symbol2'] = $item['symbol2'];
    $listItem ['requestHc'] = $item['requestHc'];
		$listItem ['paymentHc'] = $item['paymentHc'];
		$listItem ['resultHc'] = $item['resAmountHc'];
		$listItem ['accountId'] = $item['accountId'];
		$listItem ['dateDue'] = Utils::datef($item['dateDue']);

		return $listItem;
	}

	public function selectRows ()
	{
		$mq = $this->mainQueryId ();
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [journal].* ');
		array_push ($q, ', [balances].[fullName] AS [balanceFullName]');
    array_push ($q, ', [persons].[fullName] AS [personFullName]');
		array_push ($q, ', [heads].[docType] AS [docType], [heads].[docNumber] AS [docNumber]');
		array_push ($q, ' FROM [e10doc_accBal_journal] AS [journal]');
		array_push ($q, ' LEFT JOIN [e10doc_accBal_balances] AS [balances] ON [journal].[balance] = [balances].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [journal].[person] = [persons].[ndx]');
		array_push ($q, ' LEFT JOIN [e10doc_core_heads] AS [heads] ON [journal].[doc] = [heads].[ndx]');
		array_push ($q, ' WHERE 1');

		if ($this->fiscalYear)
			array_push ($q, ' AND [journal].[fiscalYear] = %i', $this->fiscalYear);

		if ($this->balanceNdx)
			array_push ($q, ' AND [journal].[balance] = %i', $this->balanceNdx);

		if ($mq === 'unpaired')
		{
			array_push($q, ' AND [balSide] = %i', 0); // request
			array_push($q, ' AND [resAmountHc] != %f', 0);
		}
		else if ($mq === 'all')
		{
			array_push($q, ' AND [balSide] = %i', 0); // request
		}
		else if ($mq === 'journal')
		{

		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [persons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [journal].[symbol1] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [balances].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}


		array_push ($q, ' ORDER BY [personFullName], [symbol1], [symbol2], [ndx]');


    array_push ($q, $this->sqlLimit());


		//$this->queryMain ($q, '[ba].', ['[balances].[order]', '[balances].[fullName]', '[ba].[systemOrder]', '[accountId]', '[ndx]']);
		$this->runQuery ($q);
	}


	public function docNumber ($doc)
	{
		//if ($this->format === 'pdf')
		//	return $r['docNumber'];

		$docId = ['table' => 'e10doc.core.heads', 'pk' => $doc['doc'], 'docAction' => 'edit'];
		$docId['text'] = $doc['docNumber'];
		$docId['icon'] = $this->docTypes[$doc['docType']]['icon'];
		//$docId['title'] = $this->docTypes[$r['docType']]['fullName'].': '.$r['docNumber'];

		//$docState = $this->tableDocs->getDocumentState ($r);
		//$docStateClass = $this->tableDocs->getDocumentStateInfo ($docState['states'], $r, 'styleClass');
		//$row['_options']['cellClasses'] = ['docNumber' => $docStateClass];

		return $docId;
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- states
		$paramsItemStates = new \Shipard\UI\Core\Params ($this->app());
		$paramsItemStates->addParam ('checkboxes', 'query.itemStates', ['cfg' => 'plans.itemStates', 'cfgTitleId' => 'fn']);
		$qry[] = ['id' => 'itemStates', 'style' => 'params', 'title' => 'Stav', 'params' => $paramsItemStates];

		// -- projects
		$q = [];
		$q [] = 'SELECT projects.* FROM [wkf_base_projects] AS projects';
		array_push ($q, ' WHERE projects.docStateMain <= %i', 2);
		array_push ($q, ' ORDER BY [order], [shortName]');
		$chbxProjects = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $pr)
			$chbxProjects[$pr['ndx']] = ['title' => $pr['shortName'], 'id' => $pr['ndx']];
		if (count($chbxProjects))
		{
			$paramsProjects = new \Shipard\UI\Core\Params ($this->app());
			$paramsProjects->addParam ('checkboxes', 'query.projects', ['items' => $chbxProjects]);
			$qry[] = ['id' => 'places', 'style' => 'params', 'title' => 'Projekt', 'params' => $paramsProjects];
		}

		// -- tags
		//UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

}
