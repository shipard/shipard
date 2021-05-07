<?php

namespace e10doc\cmnbkp\libs;
use \E10\Wizard, \E10\TableForm, \e10Doc\core\e10utils;


/**
 * Class InitStatesBalancesWizard
 * @package e10doc\cmnbkp\libs
 */
class InitStatesBalancesWizard extends \e10doc\cmnbkp\libs\InitStatesWizard
{
	public function createHeader ()
	{
		$hdr = array ();
		$hdr ['icon'] = 'icon-star-o';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Otevření účetního období - saldokonta'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Vyberte účetní období, pro které chcete vygenerovat doklady.'];
		return $hdr;
	}
}
