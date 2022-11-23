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

	public function createHeader ()
	{
		$hdr = array ();
		$hdr ['icon'] = 'user/envelope';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Hromadné rozeslání upomínek'];

		return $hdr;
	}
}
