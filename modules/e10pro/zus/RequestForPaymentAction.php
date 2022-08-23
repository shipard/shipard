<?php

namespace e10pro\zus;

require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';
require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';


/**
 * Class RequestForPaymentAction
 * @package e10pro\zus
 */
class RequestForPaymentAction extends \e10doc\balance\RequestForPaymentAction
{
	public function actionName ()
	{
		return 'Rozeslat upomínky za školné';
	}

	public function run ()
	{
		$report = new \e10pro\zus\ReportFees($this->app());
		$report->createPdf();

		foreach ($report->personsToDemand as $personNdx)
		{
			$this->sendOne($personNdx);
		}
	}
}
