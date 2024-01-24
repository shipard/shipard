<?php

namespace e10pro\soci\libs;

/**
 * class ViewWOEvents
 */
class ViewWOEvents extends \e10mnf\core\ViewWorkOrders
{
	protected function qryOrder(&$q)
	{
    array_push($q, ' ORDER BY workOrders.[docNumber]');
	}
}

