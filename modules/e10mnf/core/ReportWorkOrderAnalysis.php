<?php

namespace e10mnf\core;

use e10\FormReport, e10\utils;


/**
 * Class ReportWorkOrderAnalysis
 * @package mnf\core
 */
class ReportWorkOrderAnalysis extends FormReport
{
	var $workOrderEngine;

	function init ()
	{
		$this->reportId = 'e10mnf.core.analysis';
		$this->reportTemplate = 'e10mnf.core.analysis';
		$this->paperOrientation = 'portrait';
	}

	public function loadData ()
	{
		//utils::debugBacktrace();
		$this->setInfo('icon', 'icon-industry');
		$this->setInfo('title', $this->recData['docNumber']);
		$this->setInfo('param', 'Název', $this->recData ['title']);

		$this->workOrderEngine = $this->table->analysisEngine();
		$this->workOrderEngine->setWorkOrder($this->recData['ndx']);
		$this->workOrderEngine->doIt();

		$c = [];
		$this->workOrderEngine->createContentAll($c, 0);

		$this->data['analysis'] = $c;
	}
}
