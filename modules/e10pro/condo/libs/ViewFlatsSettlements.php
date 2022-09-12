<?php

namespace e10pro\condo\libs;


use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;


/**
 * class ViewFlatsSettlements
 */
class ViewFlatsSettlements extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsMain;
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
    array_push ($q, '');
    array_push ($q, '');
    array_push ($q, '');

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

		$listItem ['t1'] = $item['customerName'];//;

		$listItem ['i1'] = Utils::nf($item['finalAmount'], 2);
		$listItem ['t1'] = $item['title'];

    $props = [];
    $props[] = ['text' => $item['reportTitle'], 'class' => 'label label-default', 'icon' => 'user/moneyBill'];
    $props[] = ['text' => $item['woTitle'], 'class' => 'label label-default', 'icon' => 'tables/e10mnf.core.workOrders'];

    $props[] = ['text' => Utils::dateFromTo($item['dateBegin'], $item['dateEnd'], FALSE), 'class' => 'label label-default', 'icon' => 'system/iconCalendar'];

		$listItem ['t2'] = $props;

		$listItem ['icon'] = 'user/moneyBill';

		return $listItem;
	}
}
