<?php

namespace e10doc\stockIn\libs;


class StockInReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10doc.stockIn.stockIn');
	}
}


