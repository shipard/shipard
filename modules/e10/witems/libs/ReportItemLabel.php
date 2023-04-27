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
    error_log("_:__LABEL_ITEM___");
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


		//$s = '_';
		//$qrCodeData = 'SHDC_'.$this->table->ndx.'_'.addcslashes($this->recData['id'], $s);

		/*
		$qrCodeGenerator = new \lib\tools\qr\QRCodeGenerator($this->app);
		$qrCodeGenerator->textData = $qrCodeData;
		$qrCodeGenerator->createQRCode();
		$fn = $qrCodeGenerator->url;
		$this->data['mainQRCodeURL'] = $fn;
		*/

    /*
		$fullFileName = Utils::tmpFileName('svg');
		$url = $this->app()->dsRoot.'/tmp/'.basename($fullFileName);

		$generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
		$bc = $generator->getBarcode($qrCodeData, $generator::TYPE_CODE_128);
		file_put_contents($fullFileName, $bc);
		$this->data['mainQRCodeURL'] = $url;
    */
	}
}
