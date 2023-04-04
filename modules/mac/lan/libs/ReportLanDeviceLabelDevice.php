<?php

namespace mac\lan\libs;
use \Shipard\Report\FormReport;


/**
 * Class ReportLanDeviceLabelDevice
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

		$s = '-;:';
		$qrCodeData = 'TEST123-'.addcslashes($this->recData['id'], $s);
		$qrCodeGenerator = new \lib\tools\qr\QRCodeGenerator($this->app);
		$qrCodeGenerator->textData = $qrCodeData;
		$qrCodeGenerator->createQRCode();
		$fn = $qrCodeGenerator->url;
		$this->data['mainQRCodeURL'] = $fn;
	}
}
