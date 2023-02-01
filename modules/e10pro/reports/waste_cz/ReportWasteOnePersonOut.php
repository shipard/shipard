<?php
namespace e10pro\reports\waste_cz;

use \Shipard\Utils\Utils;
use \e10pro\reports\waste_cz\libs\WasteReturnEngine;

/**
 * class reportWasteOnePersonOut
 */
class ReportWasteOnePersonOut extends \e10pro\reports\waste_cz\ReportWasteOnePerson
{
	function init ()
	{
		parent::init();
		$this->setReportId('e10pro.reports.waste_cz.reportWasteOnePersonOut');

    $this->dir = WasteReturnEngine::rowDirOut;
    $this->periodTitleYearBegin = 'Celková množství odpadů, které jsme dodali v roce ';
    $this->periodTitlePeriodBegin = 'Celková množství odpadů, které jsme dodali od ';
	}

	public function loadData ()
	{
		$this->sendReportNdx = 2701;
		parent::loadData();
	}
}
