<?php

namespace e10pro\zus;

/**
 * class RequestForPaymentAction
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
