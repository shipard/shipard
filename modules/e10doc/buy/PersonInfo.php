<?php

namespace e10doc\buy;


/**
 * Class PersonInfo
 * @package e10doc\buy
 */
class PersonInfo extends \e10doc\core\PersonInfo
{
	protected function doIt ()
	{
		if (!$this->tileMode)
			return;

		// @TODO: remove
		// $this->createTimeLine('docsBuy', ['title' => 'Nákup', 'icon' => 'e10-docs-invoices-in', 'orderId' => 100,]);
	}
}