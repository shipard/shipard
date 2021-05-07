<?php

namespace e10doc\cmnbkp\libs;



/**
 * Class CloseAccPeriodWizard
 * @package e10doc\cmnbkp\libs
 */
class CloseAccPeriodWizard extends \e10doc\cmnbkp\libs\InitStatesWizard
{
	public function createHeader ()
	{
		$hdr = array ();
		$hdr ['icon'] = 'icon-stop';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Uzavření účetního období'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Vyberte účetní období, pro které chcete vygenerovat doklady.'];

		return $hdr;
	}

	public function doIt ()
	{
		$eng = new \e10doc\cmnbkp\libs\OpenClosePeriodEngine ($this->app);
		$eng->setParams($this->recData['fiscalYear'], FALSE);
		$eng->run();

		$this->stepResult ['close'] = 1;
	}
}
