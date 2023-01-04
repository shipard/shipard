<?php

namespace e10doc\sale;


/**
 * Class PersonInfo
 * @package e10doc\sale
 */
class PersonInfo extends \e10doc\core\PersonInfo
{
	protected function doIt ()
	{
		if (!$this->tileMode)
			return;
		// @TODO: remove
		// $this->createTimeLine('docsSale', ['title' => 'Prodej', 'icon' => 'e10-docs-invoices-out', 'orderId' => 200,]);
	}
}
