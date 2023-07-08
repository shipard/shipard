<?php

namespace e10pro\condo\libs;


use \Shipard\Viewer\TableView;
use \Shipard\Viewer\TableViewPanel;
use \Shipard\Utils\Utils;


/**
 * class ViewFlatsSettlements
 */
class ViewFlatsSettlements extends TableView
{
	var $settlementParam = NULL;


	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsMain;

		$this->usePanelLeft = TRUE;


		if ($this->usePanelLeft)
		{
			$defaultCR = 0;
			$enum = [];
			$crs = $this->db()->query('SELECT * FROM e10doc_reporting_calcReports ORDER BY dateBegin DESC');
			foreach ($crs as $crsRow)
			{
				$enum[$crsRow['ndx']] = ['text' => $crsRow['title'], 'class' => ''];

				if (!$defaultCR)
					$defaultCR = $crsRow['ndx'];
			}

			$enum[0] = ['text' => 'Vše', 'class' => ''];


			$this->settlementParam = new \Shipard\UI\Core\Params ($this->app);
			$this->settlementParam->addParam('switch', 'calcReportNdx', ['title' => '', 'defaultValue' => strval($defaultCR), 'switch' => $enum, 'list' => 1]);
			$this->settlementParam->detectValues();
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

    $q = [];
    array_push ($q, 'SELECT [results].*,');
    array_push ($q, ' [reports].[title] AS reportTitle, [reports].[dateBegin], [reports].[dateEnd],');
    array_push ($q, ' [wo].[title] AS woTitle, [wo].[docNumber] AS woDocNumber,');
    array_push ($q, ' [customers].[fullName] AS customerName');
    array_push ($q, ' FROM [e10doc_reporting_calcReportsResults] AS [results]');
    array_push ($q, ' LEFT JOIN [e10doc_reporting_calcReports] AS [reports] ON [results].[report] = [reports].[ndx]');
    array_push ($q, ' LEFT JOIN [e10mnf_core_workOrders] AS [wo] ON [results].[workOrder] = [wo].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [customers] ON [wo].[customer] = [customers].[ndx]');
    array_push ($q, ' WHERE 1');

		$calcReportNdx = 0;
		if ($this->settlementParam)
			$calcReportNdx = intval($this->settlementParam->detectValues()['calcReportNdx']['value']);

		if ($calcReportNdx)
			array_push($q, ' AND [results].[report] = %i', $calcReportNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [reports].[title] LIKE %s', '%'.$fts.'%');
      array_push ($q, ' OR [customers].[fullName] LIKE %s', '%'.$fts.'%');
      array_push ($q, ' OR [wo].[title] LIKE %s', '%'.$fts.'%');
      array_push ($q, ' OR [wo].[docNumber] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, 'ORDER BY [reports].[dateBegin], [wo].[docNumber], [reports].[ndx]');
		array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['i1'] = Utils::nf($item['finalAmount'], 2);
		$listItem ['t1'] = $item['title'].' / '.$item['customerName'];

    $props = [];
    $props[] = ['text' => $item['reportTitle'], 'class' => 'label label-default', 'icon' => 'user/moneyBill'];
    $props[] = ['text' => $item['woTitle'], 'class' => 'label label-default', 'icon' => 'tables/e10mnf.core.workOrders'];

    $props[] = ['text' => Utils::dateFromTo($item['dateBegin'], $item['dateEnd'], FALSE), 'class' => 'label label-default', 'icon' => 'system/iconCalendar'];

		$listItem ['t2'] = $props;

		$listItem ['icon'] = 'user/moneyBill';

		return $listItem;
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->settlementParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->settlementParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createToolbar()
	{
		return [];
	}
}
