<?php

namespace e10doc\balance;


/**
 * Class RequestForPaymentWizard
 * @package e10doc\balance
 */
class RequestForPaymentWizard extends \lib\docs\DocumentActionWizard
{
	protected function init ()
	{
		$this->actionClass = 'e10doc.balance.RequestForPaymentAction';
		parent::init();
	}
}
