<?php

namespace mac\lan\libs;
use \Shipard\Report\FormReport;


/**
 * class ReportLanDeviceLabelDeviceWWifi
 */
class ReportLanDeviceLabelDeviceWWifi extends FormReport
{
	function init ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->rasterPrint = 1;
		$this->reportId = 'reports.default.mac.lan.labelLanDeviceWWifi';
		$this->reportTemplate = 'reports.default.mac.lan.labelLanDeviceWWifi';

		$this->mimeType = 'application/x-octet-stream';

		parent::init();
	}

	public function loadData ()
	{
		$this->reportMode = FormReport::rmLabels;
		$this->rasterPrint = 1;

		parent::loadData();

		$macDeviceCfg = json_decode($this->recData['macDeviceCfg'], TRUE);

		$s = ':;"';
		$wifiQRCodeData = 'WIFI:S:'.addcslashes($macDeviceCfg['wifiSSID'] ?? '', $s).';T:WPA;P:'.addcslashes($macDeviceCfg['wifiPassword'] ?? '', $s).';;';
		$this->data['wifiQRCodeData'] = $wifiQRCodeData;

		$this->data['wifiSSID'] = $macDeviceCfg['wifiSSID'] ?? '';
		$this->data['wifiPassword'] = $macDeviceCfg['wifiPassword'] ?? '';
	}
}
