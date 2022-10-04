<?php

namespace mac\lan\libs;

use \e10\FormReport, \e10\str;


/**
 * Class ReportRackLabelDevices
 * @package mac\lan\libs
 */
class ReportRackLabelDevices extends FormReport
{
	function init ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->reportId = 'reports.default.mac.lan.racklabeldevices';
		$this->reportTemplate = 'reports.default.mac.lan.racklabeldevices';

		$this->mimeType = 'application/x-octet-stream';

		parent::init();
	}

	public function loadData ()
	{
		$this->reportMode = FormReport::rmLabels;

		parent::loadData();

		$this->loadDevices();
	}

	function loadDevices()
	{
		$q[] = 'SELECT * FROM [mac_lan_devices]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND rack = %i', $this->recData['ndx']);
		array_push ($q, ' AND docStateMain <= %i', 2);
		array_push ($q, ' ORDER BY id, ndx');

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$device = $r->toArray();

			$device['flags']['fontSizeH1'] = 0;
			$device['flags']['fontSizeH2'] = 0;
			$device['flags']['fontSizeH3'] = 0;
			$device['flags']['fontSizeH4'] = 0;

			if (str::strlen($device['id']) <= 12)
				$device['flags']['fontSizeH1'] = 1;
			elseif (str::strlen($device['id']) <= 15)
				$device['flags']['fontSizeH2'] = 1;
			elseif (str::strlen($device['id']) <= 17)
				$device['flags']['fontSizeH3'] = 1;
			else
				$device['flags']['fontSizeH4'] = 1;

			$device['flags']['evNumber'] = 0;
			if ($device['evNumber'] !== '' && $device['id'] !== $device['evNumber'])
				$device['flags']['evNumber'] = 1;

			$this->data['devices'][] = $device;
		}
	}
}
