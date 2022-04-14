<?php

namespace e10doc\proformasOut\libs;


/**
 * class ViewProformasOut
 */
class ViewProformasOut extends \e10doc\core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'invpo';
		parent::init();
	}
}
