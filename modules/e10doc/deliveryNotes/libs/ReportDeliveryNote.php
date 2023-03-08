<?php

namespace e10doc\deliveryNotes\libs;


class ReportDeliveryNote extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();
		$this->setReportId('e10doc.deliveryNote.deliverynote');
	}
}
