<?php

namespace e10mnf\reports\analysis;


use e10\Utility, e10\utils;


/**
 * Class WorkOrderAnalysisEngine
 * @package e10mnf\reports\analysis
 */
class WorkOrderAnalysisEngine extends Utility
{
	var $workOrderRec = [];

	var $moments = [];
	var $momentsTitle = NULL;
	var $lastDate = NULL;

	var $workOrderNdx = 0;
	var $accJournal = [];
	var $accSummary = [];
	var $subWorkOrdersSummary = [];

	var $documents = [];
	var $documentsSummary = [];

	var $workRecs = [];
	var $workRecsSummary = [];

	var $workInProcess = [];
	var $workInProcessSummary = [];


	var $accountsList;
	var $subWorkOrders = [];

	var $orders = [];
	var $ordersSummary = [];

	var $witems = [];
	var $witemsSummary = [];

	var $viewsData;

	var $troubles = [];

	var $accSumDefinition = [
		'default' => [
				'title' => 'Rekapitulace',
				'parts' => [
					'd' => ['title' => 'Náklady', 'sign' => '-', 'wipOrder' => '010'],
					'c' => ['title' => 'Výnosy', 'sign' => '+', 'wipOrder' => '020'],
					'x' => ['title' => 'Ostatní', 'sign' => '', 'wipOrder' => '030', 'hidden' => TRUE],
					'wip' => ['title' => 'Nedokončená výroba', 'sign' => '', 'wipOrder' => '800', 'hidden' => TRUE],
				],
				'partsSources' => [
					//'workRecs' => ['part' => 'd', 'title' => 'Odpracované hodiny']
				],
				'accounts' => [],
				'ignoredAccounts' => [],
				'moments' => [
					'begin' => ['icon' => 'icon-play-circle'],
					'lastCredit' => ['icon' => 'icon-money'],
					'end' => ['icon' => 'icon-flag-checkered'],
					'pause' => ['icon' => 'icon-pause'],
					'stop' => ['icon' => 'system/actionStop'],
					'fiscalPeriodBreak' => ['icon' => 'icon-calendar-times-o', 'class' => 'label label-default e10-me'],
				]
			]
	];

	var $viewId = 'default';
	var $viewDef;

	CONST
			woaDefault 					= 0x0001,
			woaSubWorkOrders 		= 0x0002;

	function init()
	{
		$this->viewDef = $this->accSumDefinition[$this->viewId];
		$this->loadAccountsList ();
	}

	public function setWorkOrder ($workOrderNdx)
	{
		$this->workOrderNdx = $workOrderNdx;
	}

	function addTrouble ($group, $data)
	{
		if (!isset($this->troubles[$group]))
			$this->troubles[$group][] = $data;
	}

	public function doIt()
	{
		$this->init();
		$this->loadData();
		$this->checkData();
		$this->createWorkRecsSummary();
		$this->createAccountSummary();
		$this->createOrdersSummary();
		$this->createWitemsSummary();
		$this->createSubWorkOrdersSummary();
		$this->createDocumentsSummary();
		$this->createWorkInProcessSummary();
	}

	public function loadData()
	{
		$this->loadData_workRecs();
		$this->loadData_accounting();
		$this->loadData_orders();
		$this->loadData_subWorkOrders();
	}

	function loadData_accounting()
	{
		$q [] = 'SELECT journal.*, persons.fullName AS personName, heads.docNumber AS headDocNumber, heads.title AS docTitle, heads.docType as headDocType';
		array_push ($q, ' FROM [e10doc_debs_journal] AS journal');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON journal.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON journal.document = heads.ndx ');
		array_push ($q, ' WHERE journal.workOrder = %i', $this->workOrderNdx);
		array_push ($q, ' ORDER BY journal.[dateAccounting], journal.[docNumber], journal.[ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$docType = $r['headDocType'];

			$item = [
				'date' => $r['dateAccounting'], 'accountId' => $r['accountId'], 'amountHc' => $r['money'],
				'amountHcDr' => $r['moneyDr'], 'amountHcCr' => $r['moneyCr'],
				'docType' => $docType, 'docTitle' => $r['docTitle'], 'personName' => $r['personName'], 'text' => $r['docTitle'],
				'fiscalYearId' => 'Y'.$r['fiscalYear'], 'docNdx' => $r['document']
			];

			$item['docNumber'] = $r['headDocNumber'];

			$this->accJournal[] = $item;
		}
	}

	function loadData_orders()
	{
	}

	function loadData_subWorkOrders()
	{
	}

	function loadData_workRecs()
	{
	}

	function checkData()
	{
		$this->checkData_workRecs();
		$this->checkData_subWorkOrders();
		$this->checkData_accounting();
	}

	function checkData_accounting()
	{
		$this->accJournal = \e10\sortByOneKey($this->accJournal, 'date');
		if (count($this->accJournal))
		{
			$last = array_slice($this->accJournal, -1)[0];
			$this->lastDate = $last['date'];
		}

		if (!$this->lastDate)
			$this->lastDate = utils::today();
	}

	function checkData_subWorkOrders()
	{
		$tableWorkOrders = $this->app()->table('e10mnf.core.workOrders');
		foreach ($this->subWorkOrders as $pk => &$r)
		{
			$e = $tableWorkOrders->analysisEngine();
			$e->setWorkOrder($pk);
			$e->doIt();

			$r['engine'] = $e;

			$item = [
					'date' => $e->lastDate, 'accountId' => '#subWorkOrderCost',
					'amountHc' => isset($e->viewsData['sums']['d']) ? $e->viewsData['sums']['d']['amountHc'] : 0.0,
					'amountHcDr' => 0.0,
					'amountHcCr' => isset($e->viewsData['sums']['d']) ? $e->viewsData['sums']['d']['amountHc'] : 0,
					'docType' => FALSE, 'fiscalYearId' => $e->lastDate->format('Y'),
			];

			$this->accJournal[] = $item;
		}
	}

	function checkData_workRecs()
	{
		foreach ($this->workRecs as $r)
		{
			$item = [
					'date' => $r['date'], 'accountId' => '#workrec', 'amountHc' => $r['priceAll'],
					'amountHcDr' => 0.0, 'amountHcCr' => $r['priceAll'],
					'docType' => FALSE, 'fiscalYearId' => $r['fiscalYearId']
			];

			$this->accJournal[] = $item;
		}
	}

	function createAccountSummary()
	{
		$t = [];
		$t['total'] = ['amountHc' => 0.0];

		foreach ($this->viewDef['parts'] as $partId => $onePart)
			$t['parts'][$partId] = [];

		$lastDate = NULL;
		$lastCredit = NULL;
		$lastFiscalYearId = '---';
		foreach ($this->accJournal as &$a)
		{
			if (in_array($a['accountId'], $this->viewDef['ignoredAccounts']))
				continue;

			$accDef = $this->accountSummaryDefinition($this->viewDef, $a['accountId'], $a);
			if (!$accDef)
				continue;

			$partId = $accDef['part'];
			$key = $accDef['key'];

			if (!isset($t['parts'][$partId][$key]))
				$t['parts'][$partId][$key] = ['title' => $accDef['title'], 'amountHc' => 0.0];

			$t['parts'][$partId][$key]['amountHc'] += $a['amountHc'];

			if (!isset($t['sums'][$partId]))
				$t['sums'][$partId] = ['amountHc' => 0.0];

			$t['sums'][$partId]['amountHc'] += $a['amountHc'];

			$onePart = $this->viewDef['parts'][$partId];
			$partSign = $onePart['sign'];
			if ($partSign === '+')
				$t['total']['amountHc'] += $a['amountHc'];
			elseif ($partSign === '-')
				$t['total']['amountHc'] -= $a['amountHc'];

			if ($a['docType'] !== FALSE)
			{
				$docItem = [
						'date' => $a['date'], 'docNumber' => $a['docNumber'], 'docTitle' => $a['docTitle'],
						'personName' => $a['personName'], 'text' => $a['text'], 'amountHc' => $a['amountHc'],
						'docNdx' => $a['docNdx'] ?? 0, 'docType' => $a['docType']
				];
				$docItem ['partId'] = $partId;
				$docItem ['partSign'] = $partSign;
				$this->documents[] = $docItem;
			}

			$a['wipOrder'] = $a['date']->format('Ymd').'-'.$onePart['wipOrder'].'-'.(isset($a['docNumber'])?$a['docNumber']:'');

			if (!count($this->moments))
				$this->moments[] = ['id' => 'begin', 'date' => $a['date'], 'order' => $a['date']->format('Ymd')];

			if ($partId === 'c' && $a['amountHc'] > 0.0)
				$lastCredit = $a['date'];

			//if ($partSign !== '')
				$lastDate = $a['date'];

			if ($lastFiscalYearId !== $a['fiscalYearId'])
			{
				if ($lastFiscalYearId !== '---')
					$this->moments[] = ['id' => 'fiscalPeriodBreak', 'text' => $a['fiscalYearId'], 'order' => $a['fiscalYearId'].'0101'];
			}

			$lastFiscalYearId = $a['fiscalYearId'];
		}

		if ($lastCredit)
			$this->moments[] = ['id' => 'lastCredit', 'date' => $lastCredit, 'order' => $lastCredit->format('Ymd')];

		if ($lastDate)
		{
			$mid =  (isset($this->workOrderRec['dateClosed']) && $this->workOrderRec['dateClosed']) ? 'end' : 'pause';
			$this->moments[] = ['id' => $mid, 'date' => $lastDate, 'order' => $lastDate->format('Ymd')];
		}

		$this->createAccountSummary_Overhead($t);
		//if (!isset($t['sums'][$partId]))
		//	$t['sums'][$partId] = ['amountHc' => 0.0];



		$this->createMoments();

		$amountClass = ($t['total']['amountHc'] < 0.0) ? ' e10-error' : '';
		$this->accSummary['title'] = [
			['text' => $this->viewDef['title'], 'class' => 'h2'],
			['text' => utils::nf($t['total']['amountHc'], 2), 'class' => 'h2 pull-right'.$amountClass],
		];

		foreach ($this->viewDef['parts'] as $partId => $onePart)
		{
			if (!isset($t['parts'][$partId]) || !count($t['parts'][$partId]))
				continue;
			if (isset($onePart['hidden']))
				continue;

			$titleRow = [
					'title' => $onePart['title'], 'amountHc' => $t['sums'][$partId]['amountHc'],
					'_options' => ['class' => 'subtotal', 'beforeSeparator' => 'separator'],
			];
			$this->accSummary['table'][] = $titleRow;

			foreach ($t['parts'][$partId] as $item)
			{
				$this->accSummary['table'][] = $item;
			}
		}

		$this->viewsData = $t;
	}

	function createAccountSummary_Overhead(&$t)
	{
	}

	function createMoments ()
	{
		if (isset($this->workOrderRec['dateClosed']) && $this->workOrderRec['dateClosed'])
			$this->moments[] = ['id' => 'stop', 'date' => $this->workOrderRec['dateClosed'], 'order' => $this->workOrderRec['dateClosed']->format('Ymd')];

		if (!count($this->moments))
			return;

		$this->momentsTitle = [];

		$sortedMoments = \e10\sortByOneKey($this->moments, 'order');
		foreach ($sortedMoments as $m)
		{
			$momentId = $m['id'];
			$momentDef = $this->viewDef['moments'][$momentId];
			$text = isset($m['date']) ? utils::datef($m['date']) : $m['text'];
			$class = 'e10-small';
			if (isset($momentDef['class']))
				$class .= ' '.$momentDef['class'];
			$item = ['text' => $text, 'icon' => $momentDef['icon'], 'class' => $class];
			$this->momentsTitle[] = $item;
		}
	}

	function createSubWorkOrdersSummary()
	{
		$this->subWorkOrdersSummary['header'] = ['docNumber' => 'Doklad'];
		$totalBalance = 0.0;
		foreach ($this->subWorkOrders as $pk => &$subWorkOrder)
		{
			$item = ['docNumber' => $subWorkOrder['docNumber']];

			foreach ($subWorkOrder['engine']->viewDef['parts'] as $partId => $onePart)
			{
				if ($onePart['sign'] === '')
					continue;
				$item[$partId] = $subWorkOrder['engine']->viewsData['sums'][$partId]['amountHc'];
				if (!isset($this->subWorkOrdersSummary['header'][$partId]))
					$this->subWorkOrdersSummary['header'][$partId] = ' '.$onePart['title'];

			}
			$item['balance'] = $subWorkOrder['engine']->viewsData['total']['amountHc'];
			$totalBalance += $item['balance'];

			$this->subWorkOrdersSummary['table'][] = $item;
		}

		$this->subWorkOrdersSummary['header']['balance'] = ' Výsledek';
		$this->subWorkOrdersSummary['title'] = [
				['text' => 'Výrobní příkazy', 'class' => 'h2xx'],
				['text' => utils::nf($totalBalance, 2), 'class' => 'h2xx pull-right'],
		];
	}

	function createDocumentsSummary()
	{
		$lastDn = '';
		$partsBalance = 0.0;
		$this->documentsSummary['header'] = ['date' => 'Datum', 'docNumber' => 'Doklad'];
		foreach ($this->documents as $doc)
		{
			$dn = $doc['docNumber'];
			$partId = $doc['partId'];
			$partSign = $doc['partSign'];
			$onePart = $this->viewDef['parts'][$partId];

			if ($partSign === '')
				continue;

			if ($lastDn !== $dn)
			{
				$docNumber = ['text' => $doc['docNumber'], 'title' => $doc['docTitle']."\n".$doc['personName'], 'class' => 'test',
						//'suffix' => $doc['personName'],
						'suffix' => $doc['text'],
				];
				if (isset($doc['docNdx']))
				{
					$docNumber['table'] = 'e10doc.core.heads';
					$docNumber['docAction'] = 'edit';
					$docNumber['pk'] = $doc['docNdx'];
				}
				$this->documentsSummary['table'][$dn] = [
						'docNumber' => $docNumber, 'date' => $doc['date'], 'partsBalance' => $partsBalance,
				];
			}
			if (!isset($this->documentsSummary['table'][$dn][$partId]))
				$this->documentsSummary['table'][$dn][$partId] = $doc['amountHc'];
			else
				$this->documentsSummary['table'][$dn][$partId] += $doc['amountHc'];

			if ($partSign === '+')
				$this->documentsSummary['table'][$dn]['partsBalance'] += $doc['amountHc'];
			elseif ($partSign === '-')
				$this->documentsSummary['table'][$dn]['partsBalance'] -= $doc['amountHc'];

			$partsBalance = $this->documentsSummary['table'][$dn]['partsBalance'];

			if (!isset($this->documentsSummary['header'][$partId]))
				$this->documentsSummary['header'][$partId] = ' '.$onePart['title'];

			$lastDn = $dn;
		}

		$this->documentsSummary['header']['partsBalance'] = ' Stav';
		$this->documentsSummary['title'] = [
				['text' => 'Doklady'],
		];

	}

	function createOrdersSummary()
	{
		$ordersTitle = [['value' => [['text' => 'Objednávky', 'icon' => 'icon-shopping-cart', 'class' => 'h2']]]];
		$list = ['rows' => [], 'title' => $ordersTitle];

		foreach ($this->orders as $r)
		{
			$row = ['info' => [], 'class' => 'e10-tl-row-composed'];
			$tt = [];
			$tt[] = ['text' => $r['docNumber'], 'icon' => 'icon-file-o', 'class' => '', 'prefix' => utils::datef($r['date'], '%d'), 'suffix' => $r['subjectName']];
			$tt[] = ['text' => utils::nf($r['priceTotalHC']), 'prefix' => $r['hc'], 'class' => 'pull-right'];
			if ($r['fc'] !== $r['hc'])
				$tt[] = ['text' => utils::nf($r['priceTotalFC']), 'prefix' => $r['fc'], 'class' => 'pull-right'];

			$row['title'] = $tt;


			foreach ($r['items'] as $orderItem)
			{
				$oi = [];
				$oi[] = ['text' => $orderItem['text'], 'suffix' => $orderItem['itemNdx'], 'class' => 'test'];
				$oi[] = ['text' => utils::nf($orderItem['priceTotalHC']), 'prefix' => $orderItem['hc'], 'class' => 'pull-right'];
				if ($orderItem['hc'] !== $orderItem['fc'])
					$oi[] = ['text' => utils::nf($orderItem['priceTotalFC']), 'prefix' => $orderItem['fc'], 'class' => 'pull-right'];
				//$row['info'][] = ['value' => $oi, 'infoClass' => 'block'];

				// -- příjemky
				$sd = $oi;//[];
				$sd[] = ['text' => ' ', 'class' => 'block'];
				foreach ($orderItem['stockin']['docs'] as $subDoc)
				{
					$info = ['text' => $subDoc['docNumber'], 'suffix' => $subDoc['hc'].' '.utils::nf ($subDoc['priceHC']), 'class' => 'label label-default'];
					if ($subDoc['hc'] != $subDoc['fc'])
						$info['suffix'] = $subDoc['fc'].' '.utils::nf ($subDoc['priceFC']).' '.$info['suffix'];
					$sd[] = $info;
				}

				if (count($orderItem['stockin']['docs']) && abs($orderItem['stockin']['priceTotalFC'] - $orderItem['invno']['priceTotalFC']) > 10)
				{

					$diffPrice = $orderItem['stockin']['priceTotalHC'] - $orderItem['invno']['priceTotalHC'];
					$diffLimit = max([abs(($orderItem['stockin']['priceTotalHC'] + $orderItem['invno']['priceTotalHC']) *.05), 50]);
					if (abs($diffPrice) > $diffLimit)
					{
						$sd[] = [
								'text' => 'Chybí přijaté faktury k příjemkám', 'suffix' => 'CZK ' . utils::nf($diffPrice),
								'class' => 'label label-warning'];
						$this->addTrouble('invoiceIn', 'Chybí FP');
					}
				}

				// -- faktury přijaté
				foreach ($orderItem['invno']['docs'] as $subDoc)
				{
					$info = ['text' => $subDoc['docNumber'], 'suffix' => $subDoc['hc'].' '.utils::nf ($subDoc['priceHC']), 'class' => 'label label-default'];

					if ($subDoc['hc'] != $subDoc['fc'])
						$info['suffix'] = $subDoc['fc'].' '.utils::nf ($subDoc['priceFC']).' '.$info['suffix'];

					$sd[] = $info;
				}

				$diffPrice = $orderItem['priceTotalHC'] - $orderItem['invno']['priceTotalHC'];
				$diffLimit = max([abs(($orderItem['priceTotalHC'] + $orderItem['invno']['priceTotalHC']) *.05), 50]);
				if (!count($orderItem['invno']['docs']))
				{
					$sd[] = [
							'text' => 'Chybí přijatá faktura', 'class' => 'label label-warning'];
					$this->addTrouble('invoiceIn', 'Chybí FP');
				}
				else
				if (abs($diffPrice) > $diffLimit)
				{
					$sd[] = [
							'text' => 'Nesouhlasí částka přijatých faktur', 'suffix' => 'CZK '.utils::nf($diffPrice),
							'class' => 'label label-warning'];
					$this->addTrouble('invoiceIn', 'Chybí FP');
				}

				if (count($sd))
					$row['info'][] = ['value' => $sd, 'infoClass' => 'block e10-tl-subrow'];
			}

			$list['rows'][] = $row;
		}
		$this->ordersSummary['list'] = $list;

		$this->ordersSummary['title'] = [
				['text' => 'Objednávky'],
		];
	}

	function createWitemsSummary()
	{
		$this->witemsSummary['header'] = [
				'witem' => '_Položka', 'text' => 'Název',
				'quantityIn' => ' Příjem mn', 'quantityOut' => ' Výdej mn',
				'priceInHC' => ' Příjem Kč', 'priceOutHC' => ' Výdej Kč',
		];
		$this->witemsSummary['table'] = [];
		foreach ($this->witems as $witem)
		{
			$item = [
					'witem' => $witem['itemNdx'], 'text' => $witem['text'],
					'quantityIn' => $witem['quantityIn'], 'quantityOut' => $witem['quantityOut'],
					'priceInHC' => $witem['priceInHC'], 'priceOutHC' => $witem['priceOutHC'],
			];

			if ($witem['quantityIn'] > 0.0 && $witem['quantityIn'] > $witem['quantityOut'])
			{
				$item['_options']['class'] = 'e10-warning2';
				$this->addTrouble('witems', 'Chybí výdej zásob');
			}

			$this->witemsSummary['table'][] = $item;
		}

		$this->witemsSummary['title'] = [
				['text' => 'Zásoby', 'icon' => 'e10-witems-items'],
		];
	}

	function createWorkInProcessSummary()
	{
		$t = [];
		$sortedJournal = \e10\sortByOneKey($this->accJournal, 'wipOrder');
		$view = $this->viewDef;

		$lastPartId = '';
		$lastWipSide = '';
		$lastFiscalYearId = '---';
		$rowNdx = 1;
		$wipBalance = 0.0;

		foreach (/*$this->accJournal*/$sortedJournal as $a)
		{
			if (in_array($a['accountId'], $view['ignoredAccounts']))
				continue;
			if ($a['accountId'][0] === '8')
				continue;

			$accDef = $this->accountSummaryDefinition($view, $a['accountId'], $a);
			if (!$accDef)
				continue;

			$partId = $accDef['part'];
			if ($partId === 'x')
				continue;
			$onePart = $view['parts'][$partId];

			//$key = $accDef['key'];

			if ($lastPartId !== $partId)
				$rowNdx++;

			if ($lastFiscalYearId != $a['fiscalYearId'])
			{
				$headerRow = [
						'dateBegin' => 'X'.$a['fiscalYearId'],
						'_options' => ['class' => 'subheader', 'colSpan' => ['dateBegin' => 5]
						]
				];
				$t['FY-BREAK-'.$a['fiscalYearId']] = $headerRow;
				$rowNdx++;
				$wipBalance = 0.0;
			}

			if ($partId === 'wip')
			{
				if (isset($accDef['wipPart']) && $accDef['wipPart'])
				{
					if (abs($a['amountHcDr']) > 0.01)
					{
						$wipSide = 'DR';
						$title = ($lastFiscalYearId != $a['fiscalYearId']) ? 'Počáteční stav NV' : 'Přírustek NV';
					}
					else
					{
						$wipSide = 'CR';
						$title = 'Úbytek NV';
					}
					if ($lastWipSide !== $wipSide)
						$rowNdx++;
					$rowId = 'X'.$partId.$wipSide.'-'.$rowNdx;
					if (!isset($t[$rowId]))
						$t[$rowId] = ['title' => $title, 'amountHc' => 0.0, 'wipBalance' => 0.0, 'dateBegin' => $a['date']];

					if ($wipSide === 'DR')
						$wipBalance += $a['amountHc'];
					else
						$wipBalance -= $a['amountHc'];

					$t[$rowId]['wipBalance'] = $wipBalance;

					$t[$rowId]['dateEnd'] = $a['date'];
					$t[$rowId]['amountHc'] += $a['amountHc'];
					$lastWipSide = $wipSide;
				}
			}
			else
			{
				$rowId = 'X'.$partId.'-'.$rowNdx;
				if (!isset($t[$rowId]))
					$t[$rowId] = ['title' => $onePart['title'], 'amountHc' => 0.0, 'wipBalance' => 0.0, 'dateBegin' => $a['date']];

				$t[$rowId]['dateEnd'] = $a['date'];

				$t[$rowId]['amountHc'] += $a['amountHc'];
				$t[$rowId]['wipBalance'] = $wipBalance;
			}

			$lastPartId = $partId;
			$lastFiscalYearId = $a['fiscalYearId'];
		}

		$this->workInProcessSummary = [];
		$this->workInProcessSummary['header'] = [
				'dateBegin' => 'Od', 'dateEnd' => 'Do', 'title' => 'Co', 'amountHc' => ' Částka',
		];

		foreach ($t as $rowKey => $row)
		{
			$this->workInProcessSummary['table'][] = $row;
		}

		$this->workInProcessSummary['header']['wipBalance'] = ' Stav NV';

		$this->workInProcessSummary['title'] = [
				['text' => 'Nedokončená výroba', 'class' => 'h2xx'],
		];
	}

	function createWorkRecsSummary()
	{
		$view = $this->viewDef;
		$this->workRecsSummary['all']['header'] = [
				'date' => 'Datum', 'worker' => 'Pracovník',
				'hours' => ' Hod.', 'priceHour' => ' sazba/h', 'priceAll' => ' Cena',
				'text' => 'Práce',
		];
		$totalHours = 0.0;
		$totalAmount = 0.0;

		foreach ($this->workRecs as $r)
		{
			$item = [
				'date' => $r['date'], 'worker' => $r['workerName'],
				'hours' => $r['hours'], 'priceHour' => $r['priceItem'], 'priceAll' => $r['priceAll'],
				'text' => $r['text']
			];

			$this->workRecsSummary['all']['table'][] = $item;

			$totalHours += $r['hours'];
			$totalAmount += $r['priceAll'];
		}

		$this->workRecsSummary['all']['title'] = [
				['text' => 'Práce'],
				['text' => utils::nf ($totalAmount, 0), 'suffix' => 'Kč', 'class' => 'pull-right'],
				['text' => utils::nf ($totalHours, 1), 'suffix' => 'hod', 'class' => 'pull-right'],
		];
	}

	function accountSummaryDefinition($view, $accountId, $journalRow)
	{
		foreach ($view['accounts'] as $accDef)
		{
			if (isset($accDef['accountMask']))
			{
				$l = strlen($accDef['accountMask']);
				if (substr($accountId, 0, $l) !== $accDef['accountMask'])
					continue;

				if (isset($accDef['side']))
				{
					if ($accDef['side'] === 'dr' && !$journalRow['amountHcDr'])
						continue;
					if ($accDef['side'] === 'cr' && !$journalRow['amountHcCr'])
						continue;
				}

				return $accDef;
			}
		}

		if (isset ($this->accountsList[$accountId]))
		{
			$accDef = ['title' => $this->accountsList[$accountId]['shortName'], 'key' => $accountId];
			if ($this->accountsList[$accountId]['accountKind'] == 2)
				$accDef['part'] = 'd';
			elseif ($this->accountsList[$accountId]['accountKind'] == 7)
				$accDef['part'] = 'd';
			elseif ($this->accountsList[$accountId]['accountKind'] == 3)
				$accDef['part'] = 'c';
			elseif ($this->accountsList[$accountId]['accountKind'] == 8)
				$accDef['part'] = 'c';
			else
				$accDef['part'] = 'x';

			return $accDef;
		}

		return FALSE;
	}

	function loadAccountsList ()
	{
		$q = 'SELECT * FROM e10doc_debs_accounts ORDER BY id';
		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
			$this->accountsList[$r['id']] = $r->toArray();
	}

	public function createCardContent ($target, $options = WorkOrderAnalysisEngine::woaDefault)
	{
		$cc = [];
		$this->createContentAll ($cc, $options);

		foreach ($cc as $c)
			$target->addContent('body', $c);
	}

	public function createContentAll (&$content, $options = WorkOrderAnalysisEngine::woaDefault)
	{
		$cc = [];
		$this->createContent ($cc, $options);

		$enableSubWorkOrders = ($options === WorkOrderAnalysisEngine::woaDefault || $options & WorkOrderAnalysisEngine::woaSubWorkOrders);

		if (!$enableSubWorkOrders || !count($this->subWorkOrders))
		{
			foreach ($cc as $c)
				$content[] = $c;
			return;
		}

		$tabs = [
				['title' => ['icon' => 'icon-file-o', 'text' => $this->workOrderRec['docNumber']], 'content' => $cc],
		];

		foreach ($this->subWorkOrders as $pk => $subWorkOrder)
		{
			$cc = [];
			$subWorkOrder['engine']->createContent ($cc, $options);
			$tabs[] = [
					'title' => ['icon' => 'icon-file-o', 'text' => $subWorkOrder['engine']->workOrderRec['docNumber'], 'title' => 'Pokus 123'],
					'content' => $cc
			];
		}

		$content[] =  [
				'tabsId' => 'mainTabs', 'selectedTab' => '0',
				'tabs' => $tabs,
		];
	}

	public function createContent (&$content, $options = WorkOrderAnalysisEngine::woaDefault)
	{
		$anyContent = 0;

		$h = ['title' => 'Text', 'amountHc' => ' Částka'];
		$title = $this->accSummary['title'];

		if (isset($this->momentsTitle))
		{
			$title[] = ['text' => ' ', 'class' => 'clear break block'];
			$title = array_merge($title, $this->momentsTitle);
		}
		if (count($this->troubles))
		{
			$title[] = [['text' => ' ', 'class' => 'block']];
			foreach ($this->troubles as $groupId => $troubleData)
			{
				foreach ($troubleData as $td)
					$title[] = [['text' => $td, 'class' => 'label label-warning']];
			}
		}

		if (isset($this->accSummary['table']) && count($this->accSummary['table']))
		{
			$content [] = [
					'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $this->accSummary['table'], 'header' => $h,
					'title' => $title, 'params' => ['hideHeader' => TRUE],
			];
			$anyContent++;
		}
		else
		{
			$content [] = [
					'pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => $title,
			];
		}

		// -- subWorkOrders
		//$enableSubWorkOrders = ($options === WorkOrderAnalysisEngine::woaDefault || $options & WorkOrderAnalysisEngine::woaSubWorkOrders);
		if (isset($this->subWorkOrdersSummary['table']) && count($this->subWorkOrdersSummary['table']))
		{
			$content [] = ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
					'table' => $this->subWorkOrdersSummary['table'], 'header' => $this->subWorkOrdersSummary['header'],
					'title' => $this->subWorkOrdersSummary['title'],
			];
			$anyContent++;
		}

		// -- orders
		if (isset($this->ordersSummary['list']) && count($this->ordersSummary['list']['rows']))
		{
			$content [] = [
					'pane' => 'e10-pane', 'type' => 'list', 'list' => $this->ordersSummary['list'],

			];
			$anyContent++;
		}

		if (count($this->witemsSummary['table']))
		{
			$content [] = [
					'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $this->witemsSummary['table'],
					'header' => $this->witemsSummary['header'], 'title' => $this->witemsSummary['title'],
			];
			$anyContent++;
		}

		// -- documents
		if (isset($this->documentsSummary['table']) && count($this->documentsSummary['table']))
		{
			$content [] = [
					'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $this->documentsSummary['table'],
					'header' => $this->documentsSummary['header'], 'title' => $this->documentsSummary['title'],
			];
			$anyContent++;
		}

		// -- work in process
		/*
		if (count($this->workInProcessSummary['table']))
		{
			$content [] = [
					'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $this->workInProcessSummary['table'],
					'header' => $this->workInProcessSummary['header'], 'title' => $this->workInProcessSummary['title'],
			];
			$anyContent++;
		}
		*/

		// -- workRecs
		if (count($this->workRecsSummary))
		{
			foreach ($this->workRecsSummary as $subPartId => $subPart)
			{
				if (isset($subPart['table']) && count($subPart['table']))
				{
					$content [] = [
							'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
							'table' => $subPart['table'], 'header' => $subPart['header'], 'title' => $subPart['title'],
					];
					$anyContent++;
				}
			}
		}

		// -- log
		/*
		if (count($this->accJournal))
		{
			$h = [
					'date' => 'Datum', 'docNumber' => 'Doklad', 'accountId' => 'Účet', 'amountHc' => ' Částka',
					'amountHcDr' => ' MD', 'amountHcCr' => ' DAL'
			];
			$content [] = ['type' => 'table', 'table' => $this->accJournal, 'header' => $h, 'title' => 'Testovací log'];
			$anyContent++;
		}
		*/

		if (!$anyContent)
		{
			$title = ['text' => 'Na zakázce není žádný pohyb', 'class' => 'block'];
			$content [] = [
					'pane' => 'e10-pane e10-pane-table padd5', 'type' => 'line', 'line' => $title,
			];
		}
	}
}


