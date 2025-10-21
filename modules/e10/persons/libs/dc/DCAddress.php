<?php

namespace e10\persons\libs\dc;


/**
 * Class DCAddress
 */
class DCAddress extends \e10\persons\libs\dc\DCAddressTechnical
{
	var $info = [];

	public function createContent ()
	{
		$this->showPersonDocuments = FALSE;
		$this->inForm = TRUE;
		parent::createContent();
	}
}
