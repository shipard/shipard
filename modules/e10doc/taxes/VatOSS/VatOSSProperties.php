<?php

namespace e10doc\taxes\VatOSS;

use \e10\utils, \e10\Utility;


/**
 * Class VatOSSProperties
 */
class VatOSSProperties extends \e10doc\taxes\TaxReportProperties
{
	public function load ($taxReportNdx, $filingNdx = 0)
	{
		parent::load($taxReportNdx, $filingNdx);
	}

	public function loadProperties ($taxReportNdx, $filingNdx = 0)
	{
		$this->properties['all'] = [];
		$this->properties['xml'] = [];
	}

	function createHeadProperties ()
	{
	}

	public function details(&$details)
	{
	}
}
