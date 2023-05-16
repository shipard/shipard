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

	public function addFilesToAppend(\lib\pdf\PdfCreator $pdfCreator)
	{
		$personRecData = $this->app()->loadItem($this->recData['person'], 'e10.persons.persons');
		if (!$personRecData || !$personRecData['optSendDocsAttsUnited'])
			return;

    $q = [];
    array_push($q, 'SELECT links.*, atts.[fileType], atts.[path], atts.[fileName], atts.[name]');
		array_push($q, ' FROM [e10_base_doclinks] AS [links]');
		array_push($q, ' LEFT JOIN [e10_attachments_files] AS [atts] ON [links].dstRecId = [atts].ndx');
		array_push($q, ' WHERE [links].linkId = %s', 'e10docs-send-atts');
    array_push($q, ' AND [links].srcTableId = %s', 'e10doc.core.heads', ' AND [links].srcRecId = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [links].ndx');
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
			$attFileName = __APP_DIR__.'/att/'.$r['path'].$r['fileName'];
			$pdfCreator->addFileToAppend($attFileName);
    }
	}

	public function addMessageAttachments(\Shipard\Report\MailMessage $msg)
	{
		$personRecData = $this->app()->loadItem($this->recData['person'], 'e10.persons.persons');
		if (!$personRecData || $personRecData['optSendDocsAttsUnited'])
			return;

    $q = [];
    array_push($q, 'SELECT links.*, atts.[fileType], atts.[path], atts.[fileName], atts.[name]');
		array_push($q, ' FROM [e10_base_doclinks] AS [links]');
		array_push($q, ' LEFT JOIN [e10_attachments_files] AS [atts] ON [links].dstRecId = [atts].ndx');
		array_push($q, ' WHERE [links].linkId = %s', 'e10docs-send-atts');
    array_push($q, ' AND [links].srcTableId = %s', 'e10doc.core.heads', ' AND [links].srcRecId = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [links].ndx');
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
			$attFileName = __APP_DIR__.'/att/'.$r['path'].$r['fileName'];
			$attName = $r['name'];

			$fileSuffix = '.'.$r['fileType'];
			if (!str_ends_with($attName, $fileSuffix))
				$attName .= $fileSuffix;

			$attName = Utils::safeChars($attName);

			$mimeType = mime_content_type($attFileName);
			$msg->addAttachment($attFileName, $attName, $mimeType);
    }
	}
}

