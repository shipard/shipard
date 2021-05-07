<?php

namespace E10Pro\Purchase;

require_once __APP_DIR__.'/e10-modules/e10doc/purchase/purchase.php';

use E10\utils, E10\Utility;


/**
 * Class ReportPurchasePaymentInfo
 * @package E10Pro\Purchase
 */
class ReportPurchasePaymentInfo extends \e10doc\purchase\PurchaseReport
{
	function init ()
	{
		$this->reportId = 'e10pro.purchase.paymentinfo';
		$this->reportTemplate = 'e10pro.purchase.paymentinfo';
	}
}
