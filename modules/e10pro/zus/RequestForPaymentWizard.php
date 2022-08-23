<?php

namespace e10pro\zus;


/**
 * Class RequestForPaymentWizard
 * @package e10pro\zus
 */
class RequestForPaymentWizard extends \lib\docs\DocumentActionWizard
{
	protected function init ()
	{
		$this->actionClass = 'e10pro.zus.RequestForPaymentAction';
		parent::init();
	}
}
