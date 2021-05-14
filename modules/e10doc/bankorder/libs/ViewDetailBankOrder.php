<?php

namespace e10doc\bankorder\libs;
use \e10doc\core\ViewDetailHead;



class ViewDetailBankOrder extends ViewDetailHead
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.bankorder.dc.Detail');
	}
}
