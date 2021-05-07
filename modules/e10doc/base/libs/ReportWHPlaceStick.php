<?php

namespace e10doc\base\libs;
use \e10\FormReport, \e10\str;


/**
 * Class ReportWHPlaceStick
 * @package e10doc\base\libs
 */
class ReportWHPlaceStick extends FormReport
{
	function init ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->reportId = 'e10doc.base.whplacestick';
		$this->reportTemplate = 'e10doc.base.whplacestick';

		$this->mimeType = 'application/x-octet-stream';

		parent::init();
	}

	public function loadData ()
	{
		$this->reportMode = FormReport::rmLabels;

		parent::loadData();

		$this->data['flags']['fontSizeHuge'] = 0;
		$this->data['flags']['fontSizeH1'] = 0;
		$this->data['flags']['fontSizeH2'] = 0;
		$this->data['flags']['fontSizeH3'] = 0;
		$this->data['flags']['fontSizeH4'] = 0;

		if (str::strlen($this->recData['title']) <= 8)
			$this->data['flags']['fontSizeHuge'] = 1;
		elseif (str::strlen($this->recData['title']) <= 12)
			$this->data['flags']['fontSizeH1'] = 1;
		elseif (str::strlen($this->recData['title']) <= 15)
			$this->data['flags']['fontSizeH2'] = 1;
		elseif (str::strlen($this->recData['title']) <= 17)
			$this->data['flags']['fontSizeH3'] = 1;
		else
			$this->data['flags']['fontSizeH4'] = 1;
	}
}
