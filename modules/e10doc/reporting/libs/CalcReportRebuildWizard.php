<?php

namespace e10doc\reporting\libs;

use \Shipard\Form\TableForm, \Shipard\Form\Wizard;


/**
 * class CalcReportRebuildWizard
 */
class CalcReportRebuildWizard extends Wizard
{
	var $tableCalcReports;
	var $calcReportNdx = 0;
	var $calcReportRecData;
	var $calcReportType;

	function init()
	{
		$this->tableCalcReports = $this->app()->table('e10doc.reporting.calcReports');
		$this->calcReportNdx = ($this->focusedPK) ? $this->focusedPK : $this->recData['calcReportNdx'];
		$this->calcReportRecData = $this->tableCalcReports->loadItem($this->calcReportNdx);
		$this->calcReportType = $this->app()->cfgItem('e10doc.reporting.calcReports.'.$this->calcReportRecData['calcReportType'], NULL);
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->rebuild();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->recData['calcReportNdx'] = $this->focusedPK;

		$this->openForm ();
			$this->addInput('calcReportNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function rebuild ()
	{
		$this->init();

		$crEngine = $this->app()->createObject($this->calcReportType['engine']);
		$crEngine->setCalcReport($this->calcReportNdx);
		$crEngine->doRebuild();

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'icon-refresh';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přegenerovat '.$this->calcReportRecData['title']];
		//$hdr ['info'][] = ['class' => 'info', 'value' => $this->taxReportRecData['title']];

		return $hdr;
	}
}
