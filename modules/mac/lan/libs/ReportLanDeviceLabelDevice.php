<?php

namespace mac\lan\libs;
use \Shipard\Report\FormReport;


/**
 * class ReportLanDeviceLabelDevice
 */
class ReportLanDeviceLabelDevice extends FormReport
{
	function init ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->rasterPrint = 1;
		$this->reportId = 'reports.default.mac.lan.labelLanDevice';
		$this->reportTemplate = 'reports.default.mac.lan.labelLanDevice';

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
