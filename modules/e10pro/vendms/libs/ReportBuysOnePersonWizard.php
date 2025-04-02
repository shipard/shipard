<?php

namespace e10pro\vendms\libs;


/**
 * class ReportBuysOnePersonWizard
 */
class ReportBuysOnePersonWizard extends \lib\docs\DocumentActionWizard
{
	protected function init ()
	{
		$this->actionClass = 'e10pro.vendms.libs.ReportBuysOnePersonAction';
		parent::init();
	}
}
