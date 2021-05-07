<?php

namespace mac\lan\libs;

use \e10\FormReport, \e10\str;


/**
 * Class ReportRackLabelCables
 * @package mac\lan\libs
 */
class ReportRackLabelCables extends \mac\lan\libs\ReportRackLabelDevices
{
	function init ()
	{
		parent::init();

		$this->reportId = 'mac.lan.racklabelcables';
		$this->reportTemplate = 'mac.lan.racklabelcables';
	}
}
