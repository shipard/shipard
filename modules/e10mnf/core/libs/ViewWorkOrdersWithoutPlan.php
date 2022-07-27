<?php

namespace e10mnf\core\libs;

use \Shipard\Utils\Utils, e10doc\core\libs\E10Utils, \e10doc\core\libs\Aggregate;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel;
use \e10doc\core\libs\GlobalParams;


class ViewWorkOrdersWithoutPlan extends TableView
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

    /*
		$mq [] = ['id' => 'active', 'title' => 'Živé', 'side' => 'left'];
		$mq [] = ['id' => 'done', 'title' => 'Hotové', 'side' => 'left'];
		$mq [] = ['id' => 'allActive', 'title' => 'Vše', 'side' => 'left'];

		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);
    */
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
      $nbt = [
        'id' => 0, 'title' => 'Vše',
        'active' => 1,
      ];
      $bt [] = $nbt;

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
							'active' => 0,//($activeDbCounter === $cid),
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
		$bottomTabId = intval($this->bottomTabId());

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

    array_push ($q, ' AND workOrders.[docStateMain] = %i', 1);
		array_push ($q, ' AND workOrders.[dateDeadlineRequested] > %d', '2021-12-01');

		// -- not in plans
		array_push ($q, ' AND NOT EXISTS (', 'SELECT ndx FROM plans_core_items AS t2 WHERE workOrders.ndx = t2.workOrder', ')');
		array_push ($q, ' AND NOT EXISTS (', 'SELECT ndx FROM plans_core_items AS t2 WHERE workOrders.ndx = t2.workOrderParent', ')');

    array_push($q, ' ORDER BY workOrders.[dateIssue] ' . $this->sqlLimit());

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
		$docNumber = ['icon' => 'system/iconFile', 'text' => $item ['docNumber'], 'class' => ''];
		if ($item['refId1'] !== '')
			$docNumber['suffix'] = $item['refId1'];
		$props[] = $docNumber;

		if ($item['dateIssue'])
			$props[] = ['icon' => 'system/iconDateOfOrigin', 'text' => utils::datef ($item ['dateIssue'], '%d'), 'class' => ''];

		if ($item['dateDeadlineConfirmed'])
			$props[] = ['icon' => 'system/iconCalendar', 'text' => utils::datef ($item ['dateDeadlineConfirmed'], '%d'), 'class' => ''];
		elseif ($item['dateDeadlineRequested'])
			$props[] = ['icon' => 'system/iconCalendar', 'text' => utils::datef ($item ['dateDeadlineRequested'], '%d'), 'class' => ''];

		if ($item['dateClosed'])
			$props[] = ['icon' => 'system/iconStop', 'text' => utils::datef ($item ['dateClosed'], '%d'), 'class' => ''];

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
