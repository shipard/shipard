<?php

namespace e10doc\cashRegister\libs;


class ViewCashRegisterDocsAll extends ViewCashRegisterDocs
{
	public function init ()
	{
		$this->mode = 1;
		parent::init();
	}
}
