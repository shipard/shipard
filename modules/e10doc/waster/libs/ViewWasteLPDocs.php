<?php

namespace e10doc\waster\libs;


/**
 * class ViewWasteLPDocs
 */
class ViewWasteLPDocs extends \e10doc\core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'wastelp';
		parent::init();
	}
}
