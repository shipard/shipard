<?php

namespace e10doc\invoicesOut\libs;

use e10doc\core\ShortPaymentDescriptor;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Utils;


/**
 * InvoiceOutReport
 */
class InvoiceOutReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10doc.invoiceOut.invoice');
	}

	public function loadData ()
	{
		$this->sendReportNdx = 2000;

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
		$report = $this->table->getReportData ('e10doc.core.libs.reports.DocReportISDoc', $this->recData['ndx']);
		$report->saveAs = 'isdoc-xml';
		$report->renderReport ();
		$report->createReport ();
		$report->saveReportAs();

		$pdfCreator->addAttachment($report->fullFileName, 'invoice.isdoc');

		$pdfCreator->setPdfInfo('Title', $this->data['documentName'].' '.$this->recData['docNumber']);
		$pdfCreator->setPdfInfo('Subject', $this->recData['title']);
	}

	public function addMessageAttachments(\Shipard\Report\MailMessage $msg)
	{
		$sendDocsAttachments = intval($this->app()->cfgItem ('options.experimental.sendDocsAttachments', 0));
		if (!$sendDocsAttachments)
			return;

		$attachments = UtilsBase::loadAttachments ($this->app(), [$this->recData['ndx']], 'e10doc.core.heads');
		if (isset($attachments[$this->recData['ndx']]['images']))
		{
			$attIdx = 0;
			foreach ($attachments[$this->recData['ndx']]['images'] as $a)
			{
				if (strtolower($a['filetype']) !== 'pdf')
					continue;

				$attFileName = __APP_DIR__.'/att/'.$a['path'].$a['filename'];
				$attName = $a['name'];

				if (!str_ends_with($attName, '.pdf'))
					$attName .= '.pdf';

				$attName = Utils::safeChars($attName);

				$msg->addAttachment($attFileName, $attName, 'application/pdf');
				$attIdx++;
			}
		}
	}
}

