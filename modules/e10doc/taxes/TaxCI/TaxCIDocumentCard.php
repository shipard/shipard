<?php

namespace e10doc\taxes\TaxCI;

use \e10\utils, \e10\Utility;


/**
 * Class TaxCIDocumentCard
 * @package e10doc\taxes\TaxCI
 */
class TaxCIDocumentCard extends \e10doc\taxes\TaxReportDocumentCard
{
	public function createContentErrors ()
	{
	}

	public function createContentBody ()
	{
		$this->createContentErrors();
		$this->createContentParts();
	}

	public function createContent ()
	{
		$this->init();
		$this->createContentBody ();
	}
}


