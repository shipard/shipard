<?php

namespace e10doc\offersOut\libs;


/**
 * class ViewOffersOut
 */
class ViewOffersOut extends \e10doc\core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'offro';
		parent::init();
	}
}
