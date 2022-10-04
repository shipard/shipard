<?php

namespace e10pro\ofre\libs;

/**
 * class ViewOffices
 */
class ViewOffices extends \e10mnf\core\ViewWorkOrders
{
	protected function qryOrder(&$q)
	{
    array_push($q, ' ORDER BY customers.fullName, workOrders.[docNumber]');
	}
}
