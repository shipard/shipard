<?php

namespace e10doc\deliveryNotes\libs;

class ViewDeliveryNotes extends \e10doc\core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'dlvrnote';
		parent::init();
	}
}
