<?php

namespace E10Pro\Purchase;


use E10\utils, E10\Utility;

/**
 * Oznámení o úhradě Výkupu
 *
 * Class PurchasePaymentInfo
 * @package E10Pro\Purchase
 */
class PurchasePaymentInfo extends Utility
{
	protected $transaction;
	protected $tableHeads;

	public function init ()
	{
	}

	public function setTransaction ($transaction)
	{
		$this->transaction = $transaction;
	}

	public function run ()
	{
		if ($this->transaction['amount'] >= 0.0)
			return;

		$q [] = 'SELECT * FROM [e10doc_core_heads]';
		array_push($q, ' WHERE [docType] = %s', 'purchase', ' AND [paymentMethod] = %i', 0);
		array_push($q, ' AND [symbol1] = %s', $this->transaction['symbol1']);
		array_push($q, ' AND [bankAccount] = %s', $this->transaction['bankAccount']);

		$purchase = $this->db()->query($q)->fetch();
		if (!$purchase)
			return;

		$this->tableHeads = $this->app->table ('e10doc.core.heads');
		$emails = $this->tableHeads->loadEmails ([$purchase['person']]);
		if ($emails === '')
			return;

		$formReport = $this->tableHeads->getReportData('e10pro.purchase.ReportPurchasePaymentInfo', $purchase['ndx']);
		if ($formReport === FALSE)
			return;

		$formReport->data['paymentAmount'] = utils::nf (-$this->transaction['amount'], 2);
		$formReport->data['paymentCurrency'] = $this->app->cfgItem ('e10.base.currencies.'.$this->transaction['currency'].'.shortcut');
		$formReport->data['paymentAccount'] = $this->transaction['bankAccount'];

		$formReport->renderReport();

		$msgSubject = $formReport->createReportPart('emailSubject');
		$msgBody = $formReport->createReportPart('emailBody');

		$msg = new \E10\MailMessage($this->app);

		$msg->setFrom($this->app->cfgItem('options.core.ownerFullName'), $this->app->cfgItem('options.core.ownerEmail'));
		$msg->setTo($emails);
		$msg->setSubject($msgSubject);
		$msg->setBody($msgBody);
		$msg->setDocument($this->tableHeads->tableId(), $purchase['ndx'], $formReport);

		$msg->sendMail();
		$msg->saveToOutbox();
	}
}
