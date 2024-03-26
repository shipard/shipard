<?php

namespace e10doc\offersOut\libs;


/**
 * class ReportOfferOut
 */
class ReportOfferOut extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10doc.offerOut.offro');
	}

	public function loadData ()
	{
		$this->sendReportNdx = 2701;

		parent::loadData();
	}
}
