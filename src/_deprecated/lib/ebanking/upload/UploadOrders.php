<?php

namespace lib\ebanking\upload;
use E10\utils, E10\Utility;


/**
 * Class UploadOrders
 * @package lib\ebanking\download
 */
class UploadOrders extends Utility
{
	protected $bankOrderNdx = 0;
	protected $bankOrderRecData;
	protected $tableHeads;

	protected $fileNamePdf = FALSE;
	protected $fileNameData = FALSE;
	protected $fileNameResult = FALSE;

	protected $bankAccountRec;
	protected $bankAccountNdx;

	protected $uploadResult = FALSE;

	public function init ()
	{
		$this->tableHeads = $this->app->table('e10doc.core.heads');
	}

	public function createFiles ()
	{
		$reportPdf = $this->tableHeads->getReportData ('e10doc.bankorder.libs.BankOrderReport', $this->bankOrderNdx);
		$reportPdf->renderReport ();
		$reportPdf->createReport ();

		$this->fileNamePdf = $reportPdf->fullFileName;

		$reportData = $this->tableHeads->getReportData ('e10doc.bankorder.libs.BankOrderReport', $this->bankOrderNdx);
		$reportData->saveAs = 'cz/bank-order-giro-kpc';
		$reportData->renderReport ();
		$reportData->createReport ();

		$this->fileNameData = $reportData->fullFileName;
	}

	public function setBankOrder ($bankOrderNdx)
	{
		$this->bankOrderNdx = $bankOrderNdx;
		$this->bankOrderRecData = $this->tableHeads->loadItem ($this->bankOrderNdx);
		if (!$this->bankOrderRecData)
			return;

		$this->bankAccountNdx = intval ($this->bankOrderRecData['myBankAccount']);
		$this->bankAccountRec = $this->app->loadItem($this->bankAccountNdx, 'e10doc.base.bankaccounts');

		//echo " --- ".json_encode($this->bankAccountRec)."\n";

		//$this->bankAccountLinkedPersons = \E10\Base\getDocLinks ($this->app, 'e10doc.base.bankaccounts', $this->bankAccountNdx);

	}

	protected function updateBankOrder ($fields)
	{
		$this->db()->query ('UPDATE [e10doc_core_heads] SET ', $fields, ' WHERE ndx = %i', $this->bankOrderNdx);
		if (isset($fields['docState']))
		{
			$this->tableHeads->docsLog ($this->bankOrderNdx);
		}
	}


	protected function setOrderUploadResult ()
	{
		if ($this->uploadResult === TRUE)
		{
			if ($this->fileNamePdf !== FALSE)
				\E10\Base\addAttachments($this->app, 'e10doc.core.heads', $this->bankOrderNdx, $this->fileNamePdf, '', TRUE);
			if ($this->fileNameData !== FALSE)
				\E10\Base\addAttachments($this->app, 'e10doc.core.heads', $this->bankOrderNdx, $this->fileNameData, '', TRUE);
			if ($this->fileNameResult !== FALSE)
				\E10\Base\addAttachments($this->app, 'e10doc.core.heads', $this->bankOrderNdx, $this->fileNameResult, '', TRUE);

			$this->updateBankOrder (['docState' => 4000, 'docStateMain' => 2]);
		}
	}
}
