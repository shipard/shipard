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
		$this->reportId = 'e10doc.cashregister.bill';
		$this->reportTemplate = 'e10doc.cashregister.bill';

		$this->mimeType = 'application/x-octet-stream';

		parent::init();
	}

	public function loadData ()
	{
		$this->reportMode = self::rmPOS;
		parent::loadData();
	}
}

