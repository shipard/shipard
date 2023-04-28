<?php

namespace e10\witems\libs;

use \Shipard\Report\FormReport;
use \Shipard\Utils\Utils;

/**
 * Class ReportLanDeviceLabelDevice
 */
class ReportItemLabel extends FormReport
{
	function init ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->rasterPrint = 1;
		$this->reportId = 'reports.modern.e10doc.witems.itemLabel';
		$this->reportTemplate = 'reports.modern.e10doc.witems.itemLabel';

		$this->mimeType = 'application/x-octet-stream';

		parent::init();
	}

	public function loadData ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->rasterPrint = 1;

		parent::loadData();

    $this->data['mainBCId'] = $this->table->itemMainBCId($this->recData);
	}
}
