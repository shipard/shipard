<?php

namespace e10doc\cash\libs;


class CashReport extends \e10doc\core\libs\reports\DocReport
{
	public function init ()
	{
		parent::init();

		$this->setReportId('e10doc.cash.cash');
	}

	public function loadData ()
	{
		$this->sendReportNdx = 2002;

		parent::loadData();

		if ($this->recData ['cashBoxDir'] == 1)
			$this->data ['flags']['cashDirIn'] = 1;
		else
			$this->data ['flags']['cashDirOut'] = 1;

		if ($this->recData['docType'] === 'invno')
		{
			$this->recData['print']['cashBoxDir'] = 'Příjem';
		}
	}
}
