<?php

namespace e10doc\invoicesIn\libs;


class InvoiceInReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10doc.invoiceIn.invoice');
	}
}

