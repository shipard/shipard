<?php

namespace e10doc\invoicesOut\libs;

use e10doc\core\ShortPaymentDescriptor;


class InvoiceOutReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10doc.invoiceOut.invoice');
	}

	public function loadData ()
	{
		parent::loadData();

		$spayd = new ShortPaymentDescriptor($this->app);
		$spayd->setBankAccount ('CZ', $this->data ['myBankAccount']['bankAccount'], $this->data ['myBankAccount']['iban'], $this->data ['myBankAccount']['swift']);
		$spayd->setAmount ($this->recData ['toPay'], $this->recData ['currency']);
		$spayd->setPaymentSymbols ($this->recData ['symbol1'], $this->recData ['symbol2']);

		$spayd->createString();
		$spayd->createQRCode();

		$this->data ['spayd'] = $spayd;
	}

	public function createToolbarSaveAs (&$printButton)
	{
	}

	public function saveAsFileName ($type)
	{
		$fn = $this->data ['documentName'].'-';
		$fn .= $this->recData['docNumber'].'.pdf';
		return $fn;
	}

	public function addAttachments(\lib\pdf\PdfCreator $pdfCreator)
	{
		$testISDoc = intval($this->app()->cfgItem ('options.experimental.testISDoc', 0));
		if (!$testISDoc)
			return;
		$report = $this->table->getReportData ('e10doc.core.libs.reports.DocReportISDoc', $this->recData['ndx']);
		$report->saveAs = 'isdoc-xml';
		$report->renderReport ();
		$report->createReport ();
		$report->saveReportAs();

		$pdfCreator->addAttachment($report->fullFileName, 'invoice.isdoc');

		$pdfCreator->setPdfInfo('Title', $this->data['documentName'].' '.$this->recData['docNumber']);
		$pdfCreator->setPdfInfo('Subject', $this->recData['title']);
	}
}

