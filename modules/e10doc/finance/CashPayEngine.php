<?php

namespace e10doc\finance;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/balance/balance.php';

use e10doc\balance\ReportBalance, E10Doc\Core\CreateDocumentUtility;


/**
 * Class CashPayEngine
 * @package e10doc\finance
 */
class CashPayEngine extends \E10\Utility
{
	var $personNdx = 0;
	var $documentNdx = 0;
	var $personTotals;

	public $result = ['success' => 0];

	public function setParams ($personNdx, $documentNdx = 0)
	{
		$this->personNdx = $personNdx;
		$this->documentNdx = $documentNdx;
	}

	public function getReceivables ($disableSums = FALSE)
	{
		$report = new \e10doc\balance\reportBalanceReceivables ($this->app);
		$report->init();
		$report->mode = reportBalance::bmNormal;
		$report->personNdx = $this->personNdx;
		$report->disableSums = $disableSums;

		$data = $report->prepareData();
		$this->personTotals = $report->dataSubTotals[$this->personNdx];
		return $data;
	}

	public function createCashDoc ($amount, $paymentMethod = 1)
	{
		$amountRest = $amount;

		$newDoc = new \E10Doc\Core\CreateDocumentUtility ($this->app);
		$newDoc->createDocumentHead('cash');
		$newDoc->docHead['person'] = $this->personNdx;
		$newDoc->docHead['cashBoxDir'] = 1;
		$newDoc->docHead['taxCalc'] = 0;
		$newDoc->docHead['paymentMethod'] = $paymentMethod;
		if (isset ($this->app->workplace['cashBox']))
			$newDoc->docHead['cashBox'] = $this->app->workplace['cashBox'];
		$newDoc->docHead['roundMethod'] = 1;
		$newDoc->docHead['automaticRound'] = intval($this->app()->cfgItem ('options.e10doc-sale.automaticRoundOnSale', 0));

		$dt = '';

		$rows = $this->getReceivables(TRUE);
		forEach ($rows as $r)
		{
			$money = $r['rest'];
			if ($amountRest < $money)
				$money = $amountRest;

			$newRow = $newDoc->createDocumentRow($r);
			$newRow['symbol1'] = $r['s1'];
			$newRow['priceItem'] = $money;
			$newRow['operation'] = '1030001';
			$newRow['text'] = 'Úhrada faktury č. '.$r['s1'] /*.' ze dne '.$r['date']*/;

			if ($dt !== '')
				$dt .= ', ';
			$dt .= $r['s1'];

			$newDoc->addDocumentRow ($newRow);

			$amountRest -= $money;
			if ($amountRest <= 0.0)
				break;
		}
		$newDoc->docHead['title'] = 'Úhrada faktury '.$dt;

		$newDoc->saveDocument(CreateDocumentUtility::sdsDone);
	}

	public function run()
	{
		$personNdx = intval($this->app->requestPath(4));
		$amount = floatval($this->app->requestPath(5));
		$paymentMethod = intval($this->app->requestPath(6));

		$this->setParams($personNdx);
		$this->createCashDoc($amount, $paymentMethod);

		$this->result['success'] = 1;
	}
}

