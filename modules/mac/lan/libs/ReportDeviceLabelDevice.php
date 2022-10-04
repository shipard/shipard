<?php

namespace mac\lan\libs;

use \e10\FormReport, \e10\str;


/**
 * Class ReportDeviceLabelDevice
 * @package mac\lan\libs
 */
class ReportDeviceLabelDevice extends FormReport
{
	function init ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->reportId = 'reports.default.mac.lan.devicelabeldevice';
		$this->reportTemplate = 'reports.default.mac.lan.devicelabeldevice';

		$this->mimeType = 'application/x-octet-stream';

		parent::init();
	}

	public function loadData ()
	{
		$this->reportMode = FormReport::rmLabels;

		parent::loadData();

		$this->data['flags']['fontSizeH1'] = 0;
		$this->data['flags']['fontSizeH2'] = 0;
		$this->data['flags']['fontSizeH3'] = 0;
		$this->data['flags']['fontSizeH4'] = 0;

		if (str::strlen($this->recData['id']) <= 12)
			$this->data['flags']['fontSizeH1'] = 1;
		elseif (str::strlen($this->recData['id']) <= 15)
			$this->data['flags']['fontSizeH2'] = 1;
		elseif (str::strlen($this->recData['id']) <= 17)
			$this->data['flags']['fontSizeH3'] = 1;
		else
			$this->data['flags']['fontSizeH4'] = 1;

		$this->data['flags']['evNumber'] = 0;
		if ($this->recData['evNumber'] !== '' && $this->recData['id'] !== $this->recData['evNumber'])
			$this->data['flags']['evNumber'] = 1;
	}
}
