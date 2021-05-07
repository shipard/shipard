<?php

namespace e10doc\bank\libs;


class ViewDetailBankDoc extends \e10doc\core\ViewDetailHead
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.bank.dc.Detail');
	}
}
