<?php

namespace mac\lan\libs;

use \e10\FormReport, \e10\str;


/**
 * Class ReportDeviceLabelCable
 * @package mac\lan\libs
 */
class ReportDeviceLabelCable extends \mac\lan\libs\ReportDeviceLabelDevice
{
	function init ()
	{
		parent::init();

		$this->reportId = 'reports.default.mac.lan.devicelabelcable';
		$this->reportTemplate = 'reports.default.mac.lan.devicelabelcable';
	}
}
