<?php

namespace e10doc\taxes\TaxCI;

use \e10\utils, \e10\Utility;


/**
 * Class TaxCIEngine
 * @package e10doc\taxes\TaxCI
 */
class TaxCIEngine extends \e10doc\taxes\TaxReportEngine
{
	public function init ()
	{
		$this->taxReportId = 'cz-tax-ci';
		parent::init();
	}

	public function doRebuild($recData)
	{
		$this->reportRecData = $recData;

		$e = new \e10doc\taxes\TaxCI\TaxCIDataCreator($this->app());
		$e->init();
		$e->setReport($this->reportRecData);
		$e->rebuild();
	}
}
