<?php

namespace e10doc\cash\libs;


class CashReportPos extends CashReport
{
	public function init ()
	{
		$this->reportMode = self::rmPOS;
		$this->mimeType = 'application/x-octet-stream';

		parent::init();

		$this->reportId = 'reports.default.e10doc.cash.cashpos';
		$this->reportTemplate = 'reports.default.e10doc.cash.cashpos';
	}
}
