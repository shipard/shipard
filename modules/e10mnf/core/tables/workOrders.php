<?php

namespace e10mnf\core;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \e10\utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel, \e10\TableForm, \e10\DbTable, \e10doc\core\e10utils;
use \e10doc\core\libs\GlobalParams;
use \e10\base\libs\UtilsBase;


/**
 * Class TableWorkOrders
 * @package e10mnf\core
 */
class TableWorkOrders extends DbTable
{
	CONST wotMnf = 0, wotAcc = 1;
	CONST wofOneTime = 0, wofContinuous = 1, wofPeriodic = 2;

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
		$docInfo[] = ['text' => $recData ['docNumber'], 'icon' => 'system/iconFile', 'class' => ''];
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
			'title' => $title, 'docType' => $recData['docKind'], 'docTypeName' => $dk['sn'] ?? '',
			'docID' => $recData['docNumber']/*, 'money' => $recData['toPay'], 'currency' => $recData['currency'*/
		];

		if (isset($recData['customer']) && $recData['customer'])
			$info ['persons']['to'][] = $recData['customer'];
		else
		{ // workOrder admins
			$admins = $this->db()->query('SELECT *',
												' FROM e10_base_doclinks as links ',
												' WHERE srcTableId = %s', 'e10mnf.core.workOrders',
												' AND linkId = %s', 'e10mnf-workRecs-admins',
												' AND dstTableId = %s', 'e10.persons.persons',
												' AND links.srcRecId = %i', $recData['ndx']);
			foreach ($admins as $a)
				$info ['persons']['to'][] = $a['dstRecId'];
		}

		$info ['persons']['from'][] = intval($this->app()->cfgItem ('options.core.ownerPerson', 0));

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
		$dbCounter = $this->app()->cfgItem ('e10mnf.workOrders.dbCounters.'.$recData['dbCounter'], FALSE);
		$manualNumbering = $dbCounter['manualNumbering'] ?? 0;
		if ($manualNumbering)
			return;

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

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'vdsData')
		{
			$wok = $this->app()->cfgItem ('e10mnf.workOrders.kinds.'.$recData['docKind'], NULL);
			if (!$wok || !isset($wok['vds']) || !$wok['vds'])
				return FALSE;

			$vds = $this->db()->query ('SELECT * FROM [vds_base_defs] WHERE [ndx] = %i', $wok['vds'])->fetch();
			if (!$vds)
				return FALSE;

			$sc = json_decode($vds['structure'], TRUE);
			if (!$sc || !isset($sc['fields']))
				return FALSE;

			return $sc['fields'];
		}

		return parent::subColumnsInfo ($recData, $columnId);
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
	var $personsLists = [];
	var $classification;
	var $dbCounters;
	var $fixedDbCounter = 0;

	var $useLinkedPersons = 0;
	var $linkedPersons = NULL;


	CONST vptCustomer = 0, vptDocNumber = 1, vptTitle = 2;

	public function init ()
	{
		parent::init();

		$this->createMainQueries();
		$this->createBottomTabs();

		$this->setPanels (TableView::sptQuery);

		$this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');
		$this->tableDocsHeads = $this->app()->table ('e10doc.core.heads');
	}

	protected function createMainQueries()
	{
		$mq [] = ['id' => 'active', 'title' => 'Živé', 'side' => 'left'];
		$mq [] = ['id' => 'done', 'title' => 'Hotové', 'side' => 'left'];
		$mq [] = ['id' => 'allActive', 'title' => 'Vše', 'side' => 'left'];

		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);
	}

	public function createBottomTabs ()
	{
		$enabledDbCounters = NULL;
		$enabledDbCountersStr = $this->queryParam('enabledDbCounters');
		if ($enabledDbCountersStr !== FALSE)
		{
			$enabledDbCounters = [];
			$enabledDbCountersParts = explode(',', $enabledDbCountersStr);
			foreach ($enabledDbCountersParts as $edbc)
			{
				$enabledDbCounters[] = intval(trim($edbc));
			}
			if (!count($enabledDbCounters))
				$enabledDbCounters = NULL;
		}

		// -- dbCounters
		$this->dbCounters = $this->table->app()->cfgItem ('e10mnf.workOrders.dbCounters', FALSE);
		if ($this->dbCounters !== FALSE)
		{
			$activeDbCounter = 0;
			if (count ($this->dbCounters) > 1)
			{
				forEach ($this->dbCounters as $cid => $c)
				{
					if ($enabledDbCounters && !in_array($cid, $enabledDbCounters))
						continue;
					if (!$activeDbCounter)
						$activeDbCounter = $cid;
					$addParams = ['dbCounter' => intval($cid)];
					$nbt = [
							'id' => $cid, 'title' => ($c['tabName'] !== '') ? $c['tabName'] : $c['shortName'],
							'active' => ($activeDbCounter == $cid),
							'addParams' => $addParams
					];
					$bt [] = $nbt;
				}
				if (count($bt) > 1)
					$this->setBottomTabs ($bt);
				else
				{
					$this->addAddParam ('dbCounter', $activeDbCounter);
					$this->fixedDbCounter = intval($activeDbCounter);
				}
			}
			else
				$this->addAddParam ('dbCounter', key($this->dbCounters));
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$bottomTabId = intval($this->bottomTabId());

		$q [] = 'SELECT workOrders.*, ';
		array_push ($q, ' customers.fullName as customerFullName,');
		array_push ($q, ' places.fullName as placeFullName');
		array_push ($q, ' FROM [e10mnf_core_workOrders] as workOrders');
		array_push ($q, ' LEFT JOIN e10_persons_persons as customers ON workOrders.customer = customers.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON workOrders.place = places.ndx ');
		array_push ($q, ' WHERE 1');

		// -- bottom tabs
		if ($this->fixedDbCounter)
			array_push ($q, ' AND workOrders.dbCounter = %i', $this->fixedDbCounter);
		elseif ($bottomTabId != 0)
			array_push ($q, ' AND workOrders.dbCounter = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' workOrders.docNumber LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR workOrders.title LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR customers.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR EXISTS (SELECT ndx FROM e10_base_properties WHERE workOrders.customer = e10_base_properties.recid AND tableid = %s', 'e10.persons.persons', ' AND valueString LIKE %s)', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->qryPanel($q);
		$this->qryMainQuery($q, $fts, $mainQuery);
		$this->qryOrder($q);

		array_push($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	protected function qryMainQuery(&$q, $fts, $mainQuery)
	{
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
	}

	protected function qryOrder(&$q)
	{
		$mainQuery = $this->mainQueryId ();

		if ($mainQuery === 'all')
			array_push($q, ' ORDER BY workOrders.[dateIssue] ');
		else
			array_push($q, ' ORDER BY workOrders.[docStateMain], workOrders.[dateIssue] DESC, workOrders.[ndx] DESC');
	}

	public function renderRow ($item)
	{
		$dko = $this->table->docKindOptions($item);
		$vpt = $dko['viewerPrimaryTitle'] ?? 0;

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		if ($vpt === self::vptDocNumber)
			$listItem ['t1'] = $item ['docNumber'];
		elseif ($vpt === self::vptTitle)
			$listItem ['t1'] = $item ['title'];
		else
			$listItem ['t1'] = $item ['customerFullName'];

		$listItem ['dbCounter'] = $item ['dbCounter'];
		$listItem ['currency'] = $item ['currency'];
		$listItem ['sumPrice'] = $item ['sumPrice'];

		if ($dko['workOrderType'] === TableWorkOrders::wotMnf)
		{
			if ($item['sumPrice'])
			{
				$i1 = ['text' => utils::nf ($item['sumPrice'], 2), 'prefix' => $this->currencies[$item ['currency']]['shortcut']];
				$listItem ['i1'] = $i1;
			}
		}

		if ($item ['intTitle'] !== '')
			$listItem ['t3'] = $item ['intTitle'];
		elseif ($vpt !== self::vptTitle)
			$listItem ['t3'] = $item ['title'];

		$props = [];
		$docNumber = ['icon' => 'system/iconFile', 'text' => $item ['docNumber'], 'class' => ''];
		if ($item['refId1'] !== '')
			$docNumber['suffix'] = $item['refId1'];
		$props[] = $docNumber;

		if ($dko['workOrderType'] === TableWorkOrders::wotMnf)
		{
			if ($item['dateBegin'])
				$props[] = ['icon' => 'system/iconDateOfOrigin', 'text' => utils::datef ($item ['dateBegin'], '%d'), 'class' => ''];
			else
			if ($item['dateIssue'])
				$props[] = ['icon' => 'system/iconDateOfOrigin', 'text' => utils::datef ($item ['dateIssue'], '%d'), 'class' => ''];

			if ($item['dateDeadlineConfirmed'])
				$props[] = ['icon' => 'system/iconCalendar', 'text' => utils::datef ($item ['dateDeadlineConfirmed'], '%d'), 'class' => ''];
			elseif ($item['dateDeadlineRequested'])
				$props[] = ['icon' => 'system/iconCalendar', 'text' => utils::datef ($item ['dateDeadlineRequested'], '%d'), 'class' => ''];

			if ($item['dateClosed'])
				$props[] = ['icon' => 'system/iconStop', 'text' => utils::datef ($item ['dateClosed'], '%d'), 'class' => ''];

			if ($dko['useInvoicingPeriodicity'] && $item['invoicingPeriod'])
			{
				$ip = $this->table->columnInfoEnum ('invoicingPeriod', 'cfgText');
				$props[] = ['icon' => 'docType/invoicesOut', 'text' => $ip[$item['invoicingPeriod']], 'class' => 'label label-info'];
			}
		}

		$listItem ['t2'] = $props;

		$props3 = [];

		if ($item['placeFullName'])
			$props3[] = ['icon' => 'tables/e10.base.places', 'text' => $item ['placeFullName'], 'class' => 'label label-default'];

		if (count($props3))
			$listItem ['t3'] = $props3;

		return $listItem;
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);

		$loadInvoices = 1;
		$bottomTabId = intval($this->bottomTabId());
		if (!$bottomTabId && $this->dbCounters !== FALSE)
			$bottomTabId = key ($this->dbCounters);

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

		$loadPersonsList = 0;
		if ($bottomTabId && ($this->dbCounters[$bottomTabId]['personsInViewer']))
			$loadPersonsList = 1;
		if ($loadPersonsList)
		{
			$q = [];
			array_push ($q, 'SELECT woPersons.*, persons.fullName AS personFullName');
			array_push ($q, ' FROM [e10mnf_core_workOrdersPersons] AS woPersons');
			array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON woPersons.person = persons.ndx');
			array_push ($q, ' WHERE woPersons.workOrder IN %in', $this->pks);
			array_push ($q, ' ORDER BY persons.lastName');
			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$pl = ['text' => $r['personFullName'], 'class' => 'label label-default', 'icon' => 'system/iconUser'];
				$this->personsLists[$r['workOrder']][] = $pl;
			}
		}

		if ($this->useLinkedPersons)
			$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks, 'label label-info');
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

		if (isset($this->personsLists[$item ['pk']]))
		{
			$item['t2'] = array_merge($item['t2'], $this->personsLists[$item ['pk']]);
		}


		if ($this->linkedPersons && isset ($this->linkedPersons [$item ['pk']]))
		{
			if (!isset($item ['t3']))
				$item ['t3'] = [];
			$item ['t3'] = array_merge($item ['t3'], $this->linkedPersons [$item ['pk']]);
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

		$dbCounter = $this->app()->cfgItem ('e10mnf.workOrders.dbCounters.'.$this->recData['dbCounter'], NULL);
		$manualNumbering = $dbCounter['manualNumbering'] ?? 0;

		$useDocKinds = $this->useDocKinds();
		$this->dko = $this->table->docKindOptions ($this->recData);
		$dko = $this->dko;

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
			if (!$dko['disableRows'])
				$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];
			if ($dko['usePersonsList'])
				$tabs ['tabs'][] = ['text' => 'Osoby', 'icon' => 'tables/e10.persons.persons'];
			if ($dko['useDescription'])
				$tabs ['tabs'][] = ['text' => 'Popis', 'icon' => 'formDescription'];
			if ($dko['useAddress'])
				$tabs ['tabs'][] = ['text' => 'Adresy', 'icon' => 'formAddress'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					if ($manualNumbering)
						$this->addColumnInput ('docNumber');

					if ($dko['useUsersPeriods'] ?? 0)
						$this->addColumnInput ('usersPeriod');

					if (!($dko['disableCustomer'] ?? 0))
						$this->addColumnInput ('customer');

					if ($dko['priceOnHead'])
						$this->addColumnInput ('currency');
					$this->addColumnInput ('title');

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
					if ($dko['useDateBegin'] ?? 0)
						$this->addColumnInput ('dateBegin');
					if ($dko['useDateClosed'] ?? 0)
						$this->addColumnInput ('dateClosed');
					if ($dko['useReasonClosed'] ?? 0)
						$this->addColumnInput ('reasonClosed');

					if ($dko['useInvoicingPeriodicity'])
						$this->addColumnInput ('invoicingPeriod');

					if ($dko['useDateDeadlineConfirmed'])
						$this->addColumnInput ('dateDeadlineConfirmed');

					if ($dko['priceOnHead'])
						$this->addColumnInput ('sumPrice');
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

					if ($dko['usePlaces'] ?? 0)
						$this->addColumnInput ('place');

					if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
						$this->addColumnInput ('centre');
					if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
						$this->addColumnInput ('project');

					if ($dko['useIntTitle'])
						$this->addColumnInput ('intTitle'/*, TableForm::coColW12*/);

					if ($dko['useHeadSymbol1'])
						$this->addColumnInput ('symbol1');
					//$this->addColumnInput ('symbol2');

					if ($dko['useOwnerWorkOrder'])
						$this->addColumnInput ('parentWorkOrder');
					if ($dko['useFollowUpWorkOrder'] ?? 0)
						$this->addColumnInput ('followUpWorkOrder');

					if ($dko['useMembers'])
						$this->addList ('doclinksMembers', '', TableForm::loAddToFormLayout/*|TableForm::coColW12*/);

					$this->addList ('clsf', '', TableForm::loAddToFormLayout);

					$this->addSeparator(self::coH4);
					if ($this->addSubColumns('vdsData'))
						$this->addSeparator(self::coH4);
				$this->closeTab ();
				if (!$dko['disableRows'])
				{
					$this->openTab();
						$this->addList('rows');
					$this->closeTab();
				}
				if ($dko['usePersonsList'])
				{
					$this->openTab();
						$this->addList('persons');
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
			case'dateClosed': return $dko['labelDateClosed'];
			case'reasonClosed': return $dko['labelReasonClosed'];
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

	public function validNewDocumentState ($newDocState, $saveData)
	{
		$this->dko = $this->table->docKindOptions ($this->recData);
		if ($this->dko['useDateBegin'] ?? 0)
		{
			if ($newDocState === 4000)
			{
				if ($this->dko['useDateClosed'] === 2)
				{
					if (Utils::dateIsBlank($saveData['recData']['dateClosed']))
					{
						$this->setColumnState('dateClosed', utils::es ('Hodnota'." `".$this->columnLabel($this->table->column ('dateClosed'), 0)."` ".'není vyplněna'));
						return FALSE;
					}
				}

				if ($this->dko['useReasonClosed'] === 2)
				{
					if (trim($saveData['recData']['reasonClosed']) === '')
					{
						$this->setColumnState('reasonClosed', utils::es ('Hodnota'." `".$this->columnLabel($this->table->column ('reasonClosed'), 0)."` ".'není vyplněna'));
						return FALSE;
					}
				}

				if ($saveData['recData']['ndx'])
				{
					$q = [];
					array_push($q, 'SELECT ndx, docState, docNumber, title');
					array_push($q, ' FROM [e10mnf_core_workOrders]');
					array_push($q, ' WHERE parentWorkOrder = %i', $saveData['recData']['ndx']);
					array_push($q, ' AND docState NOT IN %in', [4000, 4100, 9800]);
					$rows = $this->app()->db()->query($q);
					foreach ($rows as $r)
					{
						$this->setColumnState('title', utils::es ('Podřízená zakázka'." `".$r['docNumber']."` (".$r['title'].")".' není ukončena'));
						return FALSE;
					}
				}
			}
		}
		return parent::validNewDocumentState($newDocState, $saveData);
	}
}
