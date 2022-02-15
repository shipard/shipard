<?php

namespace e10pro\purchase;

/**
 * Class ReportPurchasePaymentInfo
 */
class ReportPurchasePaymentInfo extends \e10doc\purchase\libs\PurchaseReport
{
	function init ()
	{
		$this->reportId = 'e10pro.purchase.paymentinfo';
		$this->reportTemplate = 'e10pro.purchase.paymentinfo';
	}
}
