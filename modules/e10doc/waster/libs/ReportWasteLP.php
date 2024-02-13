<?php

namespace e10doc\waster\libs;

/**
 * class ReportWasteLP
 */
class ReportWasteLP extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();
		$this->setReportId('e10doc.waster.wastelp');
	}
}
