<?php

namespace e10buy\orders\libs\reports;

use \e10doc\core\libs\reports\DocReportBase, e10\utils;


/**
 * Class Order
 * @package e10buy\orders\libs\reports
 */
class Order extends DocReportBase
{
	function init ()
	{
		parent::init();
		$this->setReportId('e10buy.orders.order');
	}

	public function loadData ()
	{
		parent::loadData();

		$this->loadData_MainPerson('supplier');
		$this->loadData_Author();
		$this->loadData_DocumentOwner();

		// -- rows
		$q = [];
		array_push($q, 'SELECT [rows].*, items.fullName as itemFullName, items.id as itemID');
		array_push($q, ' FROM [e10buy_orders_ordersRows] as [rows]');
		array_push($q, ' LEFT JOIN e10_witems_items as items ON [rows].item = items.ndx');
		array_push($q, ' WHERE [order] = %i', $this->recData ['ndx']);
		//array_push($q, ' AND rowType != %i', 1);
		array_push($q, ' ORDER BY [rows].rowOrder, [rows].ndx');
		$rows = $this->table->db()->query($q);

		$rowNumberAll = 1;

		$tableOrderRows = $this->app()->table('e10buy.orders.ordersRows');
		foreach ($rows as $row)
		{
			$r = $row->toArray();
			$r ['print'] = $this->getPrintValues($tableOrderRows, $r);

			$this->data ['rows'][] = $r;
		}

		$this->data ['flags']['foreignCountry'] = $this->ownerCountry !== $this->country;
	}
}
