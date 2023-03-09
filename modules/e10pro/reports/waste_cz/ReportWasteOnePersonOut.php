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
		$this->sendReportNdx = 2701;
		$this->setReportId('e10pro.reports.waste_cz.reportWasteOnePersonOut');

    $this->dir = WasteReturnEngine::rowDirOut;
    $this->periodTitleYearBegin = 'Celková množství, které jsme dodali v roce ';
    $this->periodTitlePeriodBegin = 'Celková množství, které jsme dodali od ';
	}

	public function loadData ()
	{
		$this->sendReportNdx = 2701;
		parent::loadData();

		$ckDef = $this->app()->cfgItem('e10.witems.codesKinds.'.$this->codeKindNdx, NULL);
		$this->data['reportTitle'] = $ckDef['reportPersonOutTitle'] ?? '';
	}

	public function loadData2 ()
	{
		parent::loadData2();
		$ckDef = $this->app()->cfgItem('e10.witems.codesKinds.'.$this->codeKindNdx, NULL);
		$this->data['reportTitle'] = $ckDef['reportPersonOutTitle'] ?? '';
	}
}
