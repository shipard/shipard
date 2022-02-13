<?php

namespace e10doc\purchase\libs;

class PurchaseReportPos extends PurchaseReport
{
	function init()
	{
		$this->reportMode = self::rmPOS;
		$this->mimeType = 'application/x-octet-stream';

		parent::init();

		$this->reportId = 'reports.default.e10doc.purchase.purchasepos';
		$this->reportTemplate = 'reports.default.e10doc.purchase.purchasepos';
	}
}
