<?php

namespace e10doc\deliveryNotes\libs;


class ReportDeliveryNote extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		$this->reportId 			= 'e10doc.orderout.orderout';
		$this->reportTemplate = 'e10doc.orderout.orderout';
	}
}
