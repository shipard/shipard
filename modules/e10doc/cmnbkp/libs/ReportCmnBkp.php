<?php

namespace e10doc\cmnbkp\libs;



class ReportCmnBkp extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		$this->reportId = 'e10doc.cmnbkp.cmnbkp';
		$this->reportTemplate = 'e10doc.cmnbkp.cmnbkp';
	}
}
