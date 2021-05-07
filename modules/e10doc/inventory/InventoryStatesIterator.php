<?php

namespace E10Doc\Inventory;
use \E10\utils;


/**
 * Class InventoryStatesIterator
 * @package E10Doc\Inventory
 */
class InventoryStatesIterator  extends \lib\objects\ObjectsListIterator
{
	public function setTable ($table)
	{
		$table = $this->app->table ('e10doc.inventory.journal');
		parent::setTable($table);
	}

	protected function query ()
	{
		$fiscalYear = e10utils::todayFiscalYear($this->app);

		$q [] = 'SELECT ';

		if (0)
			array_push($q, ' warehouse,');

		array_push($q, ' item, SUM(quantity) as quantity,');

		if (0)
			array_push($q, ' SUM(price) as price,');

		array_push($q, ' unit');
		array_push($q, ' FROM [e10doc_inventory_journal]');

		$this->queryWhere($q);

		array_push($q, ' AND [fiscalYear] = %i', $fiscalYear);
		array_push($q, ' GROUP BY');

		if (0)
			array_push($q, ' warehouse,');

		array_push($q, ' item, unit');

		//$this->queryWhere($q);
		//$this->queryOrder($q);
		$this->queryLimit($q);

		return $q;
	}

}
