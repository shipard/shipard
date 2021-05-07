<?php

namespace e10doc\invoicesOut\libs;


class ViewInvoices extends \e10doc\core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'invno';
		parent::init();

		if ($this->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
			$this->showWorkOrders = TRUE;
	}
}
