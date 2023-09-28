<?php

namespace mac\iot\libs\reports;
use \Shipard\Report\FormReport;


/**
 * class ReportIoTDeviceLabel
 */
class ReportIoTDeviceLabel extends FormReport
{
	function init ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->rasterPrint = 1;
		$this->reportId = 'reports.default.mac.iot.labelIoTDevice';
		$this->reportTemplate = 'reports.default.mac.iot.labelIoTDevice';

		$this->mimeType = 'application/x-octet-stream';

		parent::init();
	}

	public function loadData ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->rasterPrint = 1;

		parent::loadData();
	}
}
