<?php

namespace e10pro\condo\libs;

/**
 * class ViewFlats
 */
class ViewFlats extends \e10mnf\core\ViewWorkOrders
{
	protected function qryOrder(&$q)
	{
    array_push($q, ' ORDER BY workOrders.[docNumber]');
	}
}

