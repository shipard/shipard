<?php

namespace e10doc\cash\libs;


class CashReportPos extends CashReport
{
	function command ($cmd)
	{
		$this->objectData ['mainCode'] .= chr($cmd);
	}

	public function init ()
	{
		$this->reportMode = self::rmPOS;
		$this->mimeType = 'application/x-octet-stream';

		parent::init();

		$this->reportId = 'e10doc.cash.cashpos';
		$this->reportTemplate = 'e10doc.cash.cashpos';
	}
}
