<?php

namespace mac\lan\libs;

use \e10\FormReport, \e10\str;


/**
 * Class ReportRackLabel
 * @package mac\lan\libs
 */
class ReportRackLabel extends FormReport
{
	function init ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->reportId = 'reports.default.mac.lan.racklabel';
		$this->reportTemplate = 'reports.default.mac.lan.racklabel';

		$this->mimeType = 'application/x-octet-stream';

		parent::init();
	}

	public function loadData ()
	{
		$this->reportMode = FormReport::rmLabels;

		parent::loadData();

		$tableLans = $this->app()->table('mac.lan.lans');
		$lanRecData = $tableLans->loadItem($this->recData['lan']);
		$this->data['lan'] = $lanRecData;

		$this->data['flags']['fontSizeHuge'] = 0;
		$this->data['flags']['fontSizeH1'] = 0;
		$this->data['flags']['fontSizeH2'] = 0;
		$this->data['flags']['fontSizeH3'] = 0;
		$this->data['flags']['fontSizeH4'] = 0;

		if (str::strlen($this->recData['id']) <= 8)
			$this->data['flags']['fontSizeHuge'] = 1;
		elseif (str::strlen($this->recData['id']) <= 12)
			$this->data['flags']['fontSizeH1'] = 1;
		elseif (str::strlen($this->recData['id']) <= 15)
			$this->data['flags']['fontSizeH2'] = 1;
		elseif (str::strlen($this->recData['id']) <= 17)
			$this->data['flags']['fontSizeH3'] = 1;
		else
			$this->data['flags']['fontSizeH4'] = 1;
	}
}
