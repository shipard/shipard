<?php

namespace e10doc\cashRegister\libs;


class BillReport extends \e10doc\core\libs\reports\DocReport
{
	function command ($cmd)
	{
		$this->objectData ['mainCode'] .= chr($cmd);
	}

	function init ()
	{
		$this->reportMode = self::rmPOS;
		$this->reportId = 'reports.default.e10doc.cashRegister.bill';
		$this->reportTemplate = 'reports.default.e10doc.cashRegister.bill';

		$this->mimeType = 'application/x-octet-stream';

		parent::init();
	}

	public function loadData ()
	{
		$this->reportMode = self::rmPOS;
		parent::loadData();
	}
}

