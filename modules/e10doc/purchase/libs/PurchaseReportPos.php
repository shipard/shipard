<?php

namespace e10doc\purchase\libs;

class PurchaseReportPos extends PurchaseReport
{
	function command ($cmd)
	{
		$this->objectData ['mainCode'] .= chr($cmd);
	}

	function init()
	{
		$this->reportMode = self::rmPOS;
		$this->mimeType = 'application/x-octet-stream';

		parent::init();

		$this->reportId = 'reports.default.e10doc.purchase.purchasepos';
		$this->reportTemplate = 'reports.default.e10doc.purchase.purchasepos';
	}
}
