<?php

namespace demo\documents\libs;

use \e10\utils, \e10\Utility, wkf\core\TableIssues;

class InvoiceInReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		$this->reportId = 'reports.default.demo.documents.invoiceIn';
		$this->reportTemplate = 'reports.default.demo.documents.invoiceIn';
		parent::init();
	}

	public function loadData ()
	{
		parent::loadData();

		if (isset($this->data ['myBankAccount']['bankAccount']))
		{
			$spayd = new \e10doc\core\ShortPaymentDescriptor($this->app);
			$spayd->setBankAccount('CZ', $this->data['myBankAccount']['bankAccount'], $this->data['myBankAccount']['iban'], $this->data['myBankAccount']['swift']);
			$spayd->setAmount($this->recData['toPay'], $this->recData['currency']);
			$spayd->setPaymentSymbols($this->recData['symbol1'], $this->recData['symbol2']);

			$spayd->createString();
			$spayd->createQRCode();

			$this->data['spayd'] = $spayd;
		}
	}
}
