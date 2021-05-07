<?php

namespace e10doc\core\dc;


/**
 * Class Attachments
 * @package e10doc\core\dc
 */
class Attachments extends \e10doc\core\dc\Detail
{
	public function createContent ()
	{
		$this->linkedDocuments();
		$this->attachments();
	}
}