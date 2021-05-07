<?php

namespace e10doc\cmnbkp\libs;

/**
 * Class InitStatesOthersWizard
 * @package e10doc\cmnbkp\libs
 */
class InitStatesOthersWizard extends \e10doc\cmnbkp\libs\InitStatesWizard
{
	public function createHeader ()
	{
		$hdr = array ();
		$hdr ['icon'] = 'icon-star-o';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Otevření účetního období - Aktiva a Pasiva'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Vyberte účetní období, pro které chcete vygenerovat doklady.'];
		return $hdr;
	}

	public function doIt ()
	{
		$eng = new \e10doc\cmnbkp\libs\OpenClosePeriodEngine ($this->app);
		$eng->setParams($this->recData['fiscalYear'], TRUE);
		$eng->run();

		$this->stepResult ['close'] = 1;
	}
}
