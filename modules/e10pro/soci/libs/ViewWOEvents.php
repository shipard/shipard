<?php

namespace e10pro\soci\libs;

/**
 * class ViewWOEvents
 */
class ViewWOEvents extends \e10mnf\core\ViewWorkOrders
{
	public function init ()
	{
		parent::init();

		$this->useLinkedPersons = 1;
	}

	protected function qryOrder(&$q)
	{
    array_push($q, ' ORDER BY workOrders.[docNumber]');
	}
}

