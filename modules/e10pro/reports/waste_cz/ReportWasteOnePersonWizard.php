<?php

namespace e10pro\reports\waste_cz;


/**
 * Class ReportWasteOnePersonWizard
 * @package e10pro\reports\waste_cz
 */
class ReportWasteOnePersonWizard extends \lib\docs\DocumentActionWizard
{
	protected function init ()
	{
		$this->actionClass = 'e10pro.reports.waste_cz.ReportWasteOnePersonAction';
		parent::init();
	}
}
