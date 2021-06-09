<?php

namespace e10mnf\core;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \e10\utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel, \e10\TableForm, \e10\DbTable, \e10doc\core\e10utils;
use \e10doc\core\libs\GlobalParams;

/**
 * Class TableWorkOrders
 * @package e10mnf\core
 */
class TableWorkOrders extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.core.workOrders', 'e10mnf_core_workOrders', 'Zakázky', 1120);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$tablePersons = new \E10\Persons\TablePersons ($this->app());
		$personRecData = NULL;
		if ($recData['customer'])
			$personRecData = $tablePersons->loadItem ($recData['customer']);

		if ($recData['customer'])
		{
			$pi = [
				'text' => $personRecData ['fullName'], 'icon' => $tablePersons->icon ($personRecData),
				'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $personRecData['ndx']
			];
			$hdr ['info'][] = ['class' => 'title', 'value' => $pi];
		}

		$docInfo = [];
		$docInfo[] = ['text' => $recData ['docNumber'], 'icon' => 'icon-file', 'class' => ''];
		$docInfo[] = ['text' => $recData ['title'], 'class' => 'e10-small'];

		$hdr ['info'][] = ['class' => 'info', 'value' => $docInfo];

		$currencyName = $this->app()->cfgItem ('e10.base.currencies.'.$recData['currency'].'.shortcut');
		$hdr ['sum'][] = ['class' => 'big', 'value' => '' . \E10\nf ($recData ['sumPrice'], 2), 'prefix' => $currencyName];

		return $hdr;
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData ['author']) && is_object($this->app()->user))
			$recData ['author'] = $this->app()->userNdx();

		if (!isset($recData['dateIssue']))
			$recData['dateIssue'] = utils::today();

		if (!isset($recData['docKind']))
			$recData['docKind'] = 0;
		if (isset ($recData['dbCounter']) && $recData['dbCounter'] !== 0 && $recData['docKind'] == 0)
		{
			$dbCounter = $this->app()->cfgItem ('e10mnf.workOrders.dbCounters.'.$recData['dbCounter'], []);
			$useDocKinds = utils::param ($dbCounter, 'useDocKinds', 0);
			if ($useDocKinds !== 0)
				$recData['docKind'] = $dbCounter['docKind'];
			else
				$recData['docKind'] = 0;
		}
	}

	public function checkDocumentState (&$recData)
	{
		// -- check document number
		if ($recData['docStateMain'] == 1 || $recData['docStateMain'] == 2)
		{
			if (!isset ($recData['docNumber']) || $recData['docNumber'] === '' || $recData['docNumber'][0] === '!')
				$this->makeDocNumber ($recData);
		}
	}

	protected function checkChangedInput ($changedInput, &$saveData)
	{
		$colNameParts = explode ('.', $changedInput);

		// -- row item reset
		if (count ($colNameParts) === 4 && $colNameParts[1] === 'rows' && $colNameParts[3] === 'item')
		{
			if (isset ($saveData['softChangeInput']))
			{
				$saveData['lists']['rows'][$colNameParts[2]]['itemType'] = '';
				//$saveData['lists']['rows'][$colNameParts[2]]['_fixTaxCode'] = 1;
			}
			else
			{
				$item = $this->loadItem ($saveData['lists']['rows'][$colNameParts[2]]['item'], 'e10_witems_items');
				$docType = $this->app()->cfgItem ('e10.docs.types.' . $saveData ['recData']['docType']);
				$this->resetRowItem ($saveData['recData'], $saveData['lists']['rows'][$colNameParts[2]], $item, $docType);
			}
			return;
		}

		// -- row quantity change
		if (count ($colNameParts) === 4 && $colNameParts[1] === 'rows' && $colNameParts[3] === 'quantity')
		{
			$saveData['lists']['rows'][$colNameParts[2]]['itemIsSet'] = 2;
			return;
		}
		// -- row price change
		if (count ($colNameParts) === 4 && $colNameParts[1] === 'rows' && $colNameParts[3] === 'priceItem')
		{
			$saveData['lists']['rows'][$colNameParts[2]]['itemIsSet'] = 2;
			return;
		}
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$q = "SELECT [docNumber], [title] FROM [" . $this->sqlName () . "] WHERE [ndx] = " . intval ($pk);
		$refRec = $this->app()->db()->query ($q)->fetch ();
		$refTitle = ['suffix' => $refRec ['title'], 'text' => $refRec ['docNumber']];

		return $refTitle;
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		$recData ['author'] = $this->app()->user()->data ('id');
		$recData ['docNumber'] = '';
		$recData ['dbCounterNdx'] = 0;
		$recData ['dbCounterYear'] = 0;

		$recData ['dateIssue'] = utils::today();
		unset($recData ['dateDeadlineRequested']);
		unset($recData ['dateDeadlineConfirmed']);
		unset($recData ['dateClosed']);

		return $recData;
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$dk = $this->app()->cfgItem ('e10mnf.workOrders.kinds.'.$recData['docKind'], FALSE);
		$title = $recData['title'];
		$info = [
			'title' => $title, 'docType' => $recData['docKind'], 'docTypeName' => $dk['sn'],
			'docID' => $recData['docNumber']/*, 'money' => $recData['toPay'], 'currency' => $recData['currency'*/
		];

		return $info;
	}

	public function resetRowItem ($headRecData, &$rowRecData, $itemRecData, $docType)
	{
		$rowRecData ['itemType'] = '';
		if (!$itemRecData)
			return;

		$rowRecData ['text'] = $itemRecData['fullName'];
		//$rowRecData ['taxRate'] = $itemRecData['vatRate'];
		$rowRecData ['unit'] = $itemRecData['defaultUnit'];

		$rowRecData ['priceItem'] = $itemRecData ['priceSell'];
		//$rowRecData ['taxCode'] = $this->taxCode (1, $rowRecData ['taxRate']);
	}

	public function makeDocNumber (&$recData)
	{
		$formula = '';

		if ($formula == '')
			$formula = '%C%y%4';

		if (is_string($recData['dateIssue']))
		{
			$da = new \DateTime ($recData['dateIssue']);
			$year2 = $da->format ('y');
			$year4 = $da->format ('Y');
			$month = $da->format ('m');
		}
		else
		{
			$year2 = $recData['dateIssue']->format ('y');
			$year4 = $recData['dateIssue']->format ('Y');
		}

		$recData['dbCounterYear'] = $year4;

		// make select code
		$q[] = 'SELECT MAX([dbCounterNdx]) AS maxDbCounterNdx FROM [e10mnf_core_workOrders]';
    array_push ($q, ' WHERE [dbCounter] = %i', $recData['dbCounter']);
		if (strpos ($formula, '%y') !== FALSE || strpos ($formula, '%Y') !== FALSE)
			array_push ($q, ' AND [dbCounterYear] = %i', $recData['dbCounterYear']);

		$res = $this->db()->query ($q);
		$r = $res->fetch ();

		$dbCounter = $this->app()->cfgItem ('e10mnf.workOrders.dbCounters.'.$recData['dbCounter'], FALSE);
		$dbCounterId = ($dbCounter === FALSE) ? '1' : $dbCounter ['docKeyId'];


		$firstNumber = 1;
		/*
		if ($dbCounter && isset($dbCounter['firstNumberSet']) && $dbCounter['firstNumberFiscalPeriod'] === $recData['fiscalYear'])
			$firstNumber = $dbCounter['firstNumber'];
		*/
		$dbCounterNdx = intval($r['maxDbCounterNdx']) + $firstNumber;

		$rep = [
			'%Y' => $year4, '%y' => $year2,
			'%C' => $dbCounterId,
			'%2' => sprintf ('%02d', $dbCounterNdx), '%3' => sprintf ('%03d', $dbCounterNdx),
			'%4' => sprintf ('%04d', $dbCounterNdx), '%5' => sprintf ('%05d', $dbCounterNdx)
		];
		$docNumber = strtr ($formula, $rep);

		$recData['docNumber'] = $docNumber;
		$recData['dbCounterNdx'] = $dbCounterNdx;

		return $docNumber;
	}

	public function docKindOptions ($recData)
	{
		$dk =  $this->app()->cfgItem ('e10mnf.workOrders.kinds.'.$recData['docKind'], FALSE);

		if ($dk)
			return $dk;

		$dk =  $this->app()->cfgItem ('e10mnf.workOrders.kinds.0', FALSE);
		return $dk;
	}

	public function analysisEngine()
	{
		$aeClassId = $this->app()->cfgItem ('e10mnf.cfg.WorkOrderAnalysisEngine', 'e10mnf.reports.analysis.WorkOrderAnalysisEngine');
		$e = $this->app()->createObject($aeClassId);
		return $e;
	}
}


/**
 * Class ViewWorkOrders
 * @package e10mnf\core
 */
class ViewWorkOrders extends TableView
{
	var $currencies;
	var $tableDocsHeads;
	var $invoices = [];
	var $invoicesAmounts = [];
	var $classification;
	var $dbCounters;

	public function init ()
	{
		parent::init();

		$mq [] = ['id' => 'active', 'title' => 'Živé', 'side' => 'left'];
		$mq [] = ['id' => 'done', 'title' => 'Hotové', 'side' => 'left'];
		$mq [] = ['id' => 'allActive', 'title' => 'Vše', 'side' => 'left'];

		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		$this->createBottomTabs();

		$this->setPanels (TableView::sptQuery);

		$this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');
		$this->tableDocsHeads = $this->app()->table ('e10doc.core.heads');
	}

	public function createBottomTabs ()
	{
		// -- dbCounters
		$this->dbCounters = $this->table->app()->cfgItem ('e10mnf.workOrders.dbCounters', FALSE);
		if ($this->dbCounters !== FALSE)
		{
			$activeDbCounter = key($this->dbCounters);
			if (count ($this->dbCounters) > 1)
			{
				forEach ($this->dbCounters as $cid => $c)
				{
					if (isset ($this->disabledActivitiesGroups) && in_array($c['activitiesGroup'], $this->disabledActivitiesGroups))
						continue;
					$addParams = ['dbCounter' => intval($cid)];
					$nbt = [
							'id' => $cid, 'title' => ($c['tabName'] !== '') ? $c['tabName'] : $c['shortName'],
							'active' => ($activeDbCounter === $cid),
							'addParams' => $addParams
					];
					$bt [] = $nbt;
				}
				$this->setBottomTabs ($bt);
			}
			else
				$this->addAddParam ('dbCounter', $activeDbCounter);
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$bottomTabId = $this->bottomTabId();

		$q [] = 'SELECT workOrders.*, ';
		array_push ($q, ' customers.fullName as customerFullName ');
		array_push ($q, ' FROM [e10mnf_core_workOrders] as workOrders');
		array_push ($q, ' LEFT JOIN e10_persons_persons as customers ON workOrders.customer = customers.ndx');
		array_push ($q, ' WHERE 1');

		// -- bottom tabs
		if ($bottomTabId != 0)
			array_push ($q, ' AND workOrders.dbCounter = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' workOrders.docNumber LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR workOrders.title LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR customers.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->qryPanel($q);

		if ($mainQuery === 'active' || $mainQuery == '')
		{
			if ($fts != '')
				array_push ($q, ' AND workOrders.[docStateMain] IN (0, 1, 2)');
			else
				array_push ($q, ' AND workOrders.[docStateMain] IN (0, 1)');
		}

		if ($mainQuery === 'done')
			array_push ($q, ' AND workOrders.[docStateMain] = 2');
			//array_push ($q, ' AND workOrders.[dateClosed] IS NOT NULL');

		if ($mainQuery === 'discarded')
			array_push ($q, ' AND workOrders.[docStateMain] = 5');
		if ($mainQuery === 'trash')
			array_push ($q, ' AND workOrders.[docStateMain] = 4');


		if ($mainQuery === 'all')
			array_push($q, ' ORDER BY workOrders.[dateIssue] ' . $this->sqlLimit());
		else
			array_push($q, ' ORDER BY workOrders.[docStateMain], workOrders.[dateIssue] DESC, workOrders.[ndx] DESC' . $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item ['customerFullName'];
		$listItem ['dbCounter'] = $item ['dbCounter'];
		$listItem ['currency'] = $item ['currency'];
		$listItem ['sumPrice'] = $item ['sumPrice'];

		$i1 = ['text' => utils::nf ($item['sumPrice'], 2), 'prefix' => $this->currencies[$item ['currency']]['shortcut']];
		$listItem ['i1'] = $i1;
		//$i2 = ['text' => utils::nf ($item['sumPriceHc'], 2), 'prefix' => $this->currencies[$item ['homeCurrency']]['shortcut']];
		//$listItem ['i2'] = $i2;

		if ($item ['intTitle'] !== '')
			$listItem ['t3'] = $item ['intTitle'];
		else
			$listItem ['t3'] = $item ['title'];

		$props = [];
		$docNumber = ['icon' => 'icon-file', 'text' => $item ['docNumber'], 'class' => ''];
		if ($item['refId1'] !== '')
			$docNumber['suffix'] = $item['refId1'];
		$props[] = $docNumber;

		if ($item['dateIssue'])
			$props[] = ['icon' => 'icon-play-circle', 'text' => utils::datef ($item ['dateIssue'], '%d'), 'class' => ''];

		if ($item['dateDeadlineConfirmed'])
			$props[] = ['icon' => 'icon-calendar-check-o', 'text' => utils::datef ($item ['dateDeadlineConfirmed'], '%d'), 'class' => ''];
		elseif ($item['dateDeadlineRequested'])
			$props[] = ['icon' => 'system/iconCalendar', 'text' => utils::datef ($item ['dateDeadlineRequested'], '%d'), 'class' => ''];

		if ($item['dateClosed'])
			$props[] = ['icon' => 'system/actionStop', 'text' => utils::datef ($item ['dateClosed'], '%d'), 'class' => ''];

		$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);

		$loadInvoices = 1;
		$bottomTabId = intval($this->bottomTabId());

		if ($bottomTabId && $this->dbCounters[$bottomTabId]['invoicesInViewer'] === 0)
			$loadInvoices = 0;

		if ($loadInvoices)
		{
			$q[] = 'SELECT [rows].document, [rows].workOrder, [rows].priceAll,';
			array_push($q, ' heads.docNumber, heads.docState AS docDocState, heads.docStateMain AS docDocStateMain,');
			array_push($q, ' heads.currency AS headCurrency, heads.dateAccounting AS headDateAccounting');
			array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
			array_push($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');
			array_push($q, ' WHERE [rows].workOrder IN %in', $this->pks, ' AND heads.docType = %s', 'invno');
			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$woNdx = $r['workOrder'];
				$hc = $r['headCurrency'];
				$docNumber = $r['docNumber'];
				if (isset($this->invoices[$woNdx][$hc][$docNumber]))
					$this->invoices[$woNdx][$hc][$docNumber]['amount'] += $r['priceAll'];
				else
				{
					$this->invoices[$woNdx][$hc][$docNumber]['amount'] = $r['priceAll'];

					$docItem = ['docState' => $r['docDocState'], 'docStateMain' => $r['docDocStateMain'], 'docType' => 'invno'];
					$docDocState = $this->tableDocsHeads->getDocumentState($docItem);
					$docDocStateClass = $this->tableDocsHeads->getDocumentStateInfo($docDocState['states'], $docItem, 'styleClass');
					$this->invoices[$woNdx][$hc][$docNumber]['docStateClass'] = $docDocStateClass;
				}

				if (!isset($this->invoicesAmounts[$woNdx][$hc]))
					$this->invoicesAmounts[$woNdx][$hc] = 0.0;
				if ($r['docDocStateMain'] === 2 && $r['docDocState'] !== 4100)
					$this->invoicesAmounts[$woNdx][$hc] += $r['priceAll'];
			}
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}

		$dbc = $this->dbCounters[$item['dbCounter']];

		if ($dbc['invoicesInViewer'] !== 0 && isset ($this->invoices[$item ['pk']]))
		{
			foreach ($this->invoices[$item ['pk']] as $currencyId => $currencyInvoices)
			{
				if ($dbc['invoicesInViewer'] === 1)
				{
					$invoicesTotal = $this->invoicesAmounts[$item ['pk']][$currencyId];
					$currencyName = $this->currencies[$currencyId]['shortcut'];

					$label = [
						'text' => utils::nf($invoicesTotal, 2),
						'suffix' => $currencyName, 'icon' => 'e10-docs-invoices-out', 'class' => 'pull-right label label-default'
					];

					if ($item['currency'] === $currencyId)
					{
						$percents = 0;
						if ($item['sumPrice'])
						{
							$percents = round($invoicesTotal / $item['sumPrice'] * 100, 0);
							$label['prefix'] = $percents.' %';
						}

						if ($percents >= 100)
							$label['class'] = 'pull-right label label-success';
						elseif ($percents >= 50)
							$label['class'] = 'pull-right label label-warning';
					}
					$item['t2'][] = $label;
					continue;
				}

				$inv = [];
				$totalCnt = count($this->invoices[$item ['pk']]);
				$plus = NULL;
				$plusCnt = 0;
				$max = 2;
				$cnt = 0;
				foreach ($currencyInvoices as $docNumber => $doc)
				{
					$cnt++;
					if ($cnt <= $max || (!$plusCnt && ($totalCnt - $cnt) == 0))
					{
						$inv[] = ['text' => $docNumber, 'suffix' => utils::nf($doc['amount'], 2), 'class' => 'tag tag-small ' . $doc['docStateClass'], 'icon' => 'e10-docs-invoices-out'];
					} else
					{
						if ($plus === NULL)
							$plus = ['class' => 'tag tag-small e10-docstyle-done', 'icon' => 'e10-docs-invoices-out', 'amount' => 0.0];
						$plus['amount'] += $doc['amount'];
						$plusCnt++;
					}
				}
				$item['t2'] = array_merge($item['t2'], $inv);
				if ($plus)
				{
					$plus['text'] = '+ ' . $plusCnt . ' dalších';
					$plus['suffix'] = utils::nf($plus['amount'], 2);
					$item['t2'][] = $plus;
				}
			}
		}
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		$paramsRows = new GlobalParams ($panel->table->app());
		$paramsRows->addParam ('string', 'query.rows.text', ['title' => 'Text řádku']);
		$paramsRows->addParam ('float', 'query.rows.amount', ['title' => 'Částka']);
		$paramsRows->addParam ('float', 'query.rows.amountDiff', ['title' => '+/-']);

		//$qry[] = ['id' => 'paramDates', 'style' => 'params', 'title' => 'Období', 'params' => $paramsDates];
		$qry[] = ['id' => 'paramRows', 'style' => 'params', 'title' => 'Hledat v řádcích', 'params' => $paramsRows];

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = ['style' => 'params', 'title' => $cg['name'], 'params' => $params];
		}

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function qryPanel (array &$q)
	{
		$qv = $this->queryValues();

		//e10utils::datePeriodQuery('dateIssue', $q, $qv);

		/*if (isset ($qv['period']['fiscal']))
			e10utils::fiscalPeriodQuery($q, $qv['period']['fiscal']);
		if (isset ($qv['period']['vat']))
			e10utils::vatPeriodQuery($q, $qv['period']['vat']);
		*/


		$rowsQuery = 0;
		if (isset($qv['rows']['text']) && $qv['rows']['text'] != '')
			$rowsQuery = 1;
		if (isset ($qv['rows']['amount']) && $qv['rows']['amount'] != '')
			$rowsQuery = 1;

		if ($rowsQuery)
		{
			array_push($q, ' AND EXISTS (SELECT ndx FROM e10mnf_core_workOrdersRows AS [rows] WHERE workOrders.ndx = [rows].workOrder');

			if (isset($qv['rows']['text']) && $qv['rows']['text'] != '')
			{
				array_push($q, ' AND [rows].[text] LIKE %s', '%'.$qv['rows']['text'].'%');
			}

			e10utils::amountQuery ($q, '[rows].[priceAll]', $qv['rows']['amount'], $qv['rows']['amountDiff']);

			array_push($q, ' )');
		}


		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE workOrders.ndx = recid AND tableId = %s', 'e10mnf.core.workOrders');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}
	}
}


/**
 * Class ViewDetailWorkOrder
 * @package e10mnf\core
 */
class ViewDetailWorkOrder extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard ('e10mnf.core.WorkOrderCard');
	}
}


/**
 * Class ViewDetailWorkOrderAnalysis
 * @package e10mnf\core
 */
class ViewDetailWorkOrderAnalysis extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard ('e10mnf.core.WorkOrderCardAnalysis');
	}
}


/**
 * Class ViewDetailWorkOrderAccounting
 * @package e10mnf\core
 */
class ViewDetailWorkOrderAccounting extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer('e10doc.debs.journal', 'e10doc.debs.ViewJournalDoc', ['workOrder' => $this->item['ndx']]);
	}
}


/**
 * Class FormWorkOrder
 * @package e10mnf\core
 */
class FormWorkOrder extends TableForm
{
	var $dko = NULL;

	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$useDocKinds = $this->useDocKinds();
		$this->dko = $this->table->docKindOptions ($this->recData);
		$dko = $this->dko;

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'x-content'];
			if (!$dko['disableRows'])
				$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'x-properties'];
			if ($dko['useDescription'])
				$tabs ['tabs'][] = ['text' => 'Popis', 'icon' => 'icon-pencil'];
			if ($dko['useAddress'])
				$tabs ['tabs'][] = ['text' => 'Adresy', 'icon' => 'icon-home'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-attachments'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->layoutOpen (TableForm::ltHorizontal);
						$this->layoutOpen (TableForm::ltForm);
							$this->addColumnInput ('customer');
							if ($useDocKinds === 2)
								$this->addColumnInput ('docKind');
							if ($dko['useDateIssue'])
								$this->addColumnInput ('dateIssue');
							if ($dko['useRefId1'])
								$this->addColumnInput ('refId1');
							if ($dko['useRefId2'])
								$this->addColumnInput ('refId2');
							if ($dko['useDateContract'])
								$this->addColumnInput ('dateContract');
							if ($dko['useDateDeadlineRequested'])
								$this->addColumnInput ('dateDeadlineRequested');
							if ($dko['useDateBegin'])
								$this->addColumnInput ('dateBegin');
							if ($dko['useDateDeadlineConfirmed'])
								$this->addColumnInput ('dateDeadlineConfirmed');
						$this->layoutClose ('width50');

						$this->layoutOpen (TableForm::ltForm);
							if ($dko['priceOnHead'])
								$this->addColumnInput ('sumPrice');
							$this->addColumnInput ('currency');
							if ($this->recData ['currency'] !== $this->recData ['homeCurrency'])
								$this->addColumnInput ('exchangeRate');

							if ($dko['useRetentionGuarantees'] || $this->recData['retentionGuarantees'] != 0)
							{
								$this->addColumnInput ('retentionGuarantees');
								if ($this->recData['retentionGuarantees'] == 1)
									$this->addColumnInput ('rgAmount');
								elseif ($this->recData['retentionGuarantees'] == 2)
									$this->addColumnInput ('rgPercent');
							}

							if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
								$this->addColumnInput ('centre');
							if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
								$this->addColumnInput ('project');
						$this->layoutClose ();
					$this->layoutClose ();
					//$this->layoutOpen (TableForm::ltForm/*TableForm::ltGrid*/);
						$this->addColumnInput ('title'/*, TableForm::coColW12*/);
						if ($dko['useIntTitle'])
							$this->addColumnInput ('intTitle'/*, TableForm::coColW12*/);
						$this->addList ('doclinks', '', TableForm::loAddToFormLayout/*|TableForm::coColW12*/);
						$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					//$this->layoutClose ();
				$this->closeTab ();
				if (!$dko['disableRows'])
				{
					$this->openTab();
						$this->addList('rows');
					$this->closeTab();
				}
				if ($dko['useDescription'])
				{
					$this->openTab(TableForm::ltNone);
						$this->addInputMemo ('description', NULL, TableForm::coFullSizeY);
					$this->closeTab();
				}
				if ($dko['useAddress'])
				{
					$this->openTab();
						$this->addList ('address', '', TableForm::loAddToFormLayout);
					$this->closeTab();
				}
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function checkAfterSave ()
	{
		if ($this->recData['docNumber'] == '')
			$this->recData['docNumber'] = '!'.sprintf ('%09d', $this->recData['ndx']);

		$this->calcMoney();

		return TRUE;
	}

	public function calcMoney ()
	{
		$dk =  $this->table->docKindOptions ($this->recData);
		if (!$dk['disableRows'])
		{
			// sum money
			$q = 'SELECT SUM(quantity) as quantity, SUM(priceAll) as priceAll, SUM(priceAllHc) as priceAllHc FROM [e10mnf_core_workOrdersRows] WHERE [workOrder] = %i';
			$sum = $this->table->db()->query($q, $this->recData['ndx'])->fetch();
			$this->recData ['sumPrice'] = $sum ['priceAll'];
		}
		if ($dk['disableRows'] && !$dk['priceOnHead'])
		{
			$this->recData ['sumPrice'] = 0.0;
			$this->recData ['sumPriceHc'] = 0.0;
		}

		if ($this->recData['retentionGuarantees'] == 0)
		{
			$this->recData['rgAmount'] = 0.0;
			$this->recData['rgAmountHc'] = 0.0;
			$this->recData['rgPercent'] = 0.0;
		}
		elseif ($dk['useRetentionGuarantees'] || $this->recData['retentionGuarantees'] != 0)
		{
			if ($this->recData['retentionGuarantees'] == 1)
			{ // by amount
				$this->recData['rgPercent'] = round(($this->recData['rgAmount'] / $this->recData ['sumPrice']) * 100.0, 1);
			}
			elseif ($this->recData['retentionGuarantees'] == 2)
			{ // by percent
				$this->recData['rgAmount'] = round($this->recData['sumPrice'] * ($this->recData ['rgPercent'] / 100.0), 0);
			}
		}

		// -- exchange rate
		if ($this->recData ['currency'] !== $this->recData ['homeCurrency'])
		{
			$this->recData ['sumPriceHc'] = round($this->recData ['sumPrice'] * $this->recData ['exchangeRate'], 2);
			$this->recData['rgAmountHc'] = round($this->recData['rgAmount'] * $this->recData ['exchangeRate'], 2);
		} else
		{
			$this->recData ['sumPriceHc'] = $sum ['priceAllHc'];
			$this->recData['rgAmountHc'] = $this->recData['rgAmount'];
		}
	}

	function columnLabel ($colDef, $options)
	{
		$dko = $this->dko;
		switch ($colDef ['sql'])
		{
			case'dateIssue': return $dko['labelDateIssue'];
			case'dateContract': return $dko['labelDateContract'];
			case'dateBegin': return $dko['labelDateBegin'];
			case'dateDeadlineRequested': return $dko['labelDateDeadlineRequested'];
			case'dateDeadlineConfirmed': return $dko['labelDateDeadlineConfirmed'];
			case'refId1': return $dko['labelRefId1'];
			case'refId2': return $dko['labelRefId2'];
		}

		return parent::columnLabel ($colDef, $options);
	}

	protected function useDocKinds ()
	{
		$useDocKinds = 0;
		if (isset ($this->recData['dbCounter']) && $this->recData['dbCounter'] !== 0)
		{
			$dbCounter = $this->app()->cfgItem ('e10mnf.workOrders.dbCounters.'.$this->recData['dbCounter'], FALSE);
			$useDocKinds = utils::param ($dbCounter, 'useDocKinds', 0);
		}

		return $useDocKinds;
	}
}
