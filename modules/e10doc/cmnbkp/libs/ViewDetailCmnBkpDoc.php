<?php

namespace e10doc\cmnbkp\libs;

class ViewDetailCmnBkpDoc extends \e10doc\core\ViewDetailHead
{
	public function createDetailContent ()
	{
		$this->addDocumentCard ('e10doc.cmnbkp.dc.Detail');
		return;
	}
}
