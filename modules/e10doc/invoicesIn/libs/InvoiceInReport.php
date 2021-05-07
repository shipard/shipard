<?php

namespace e10doc\invoicesIn\libs;


class InvoiceInReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		$this->reportId = 'e10doc.invoicesIn.invni';
		$this->reportTemplate = 'e10doc.invoicesIn.invni';
	}
}

