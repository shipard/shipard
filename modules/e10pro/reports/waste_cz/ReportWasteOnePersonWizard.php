<?php

namespace e10pro\reports\waste_cz;


/**
 * class ReportWasteOnePersonWizard
 */
class ReportWasteOnePersonWizard extends \lib\docs\DocumentActionWizard
{
	protected function init ()
	{
		$this->actionClass = 'e10pro.reports.waste_cz.ReportWasteOnePersonAction';
		parent::init();
	}
}
