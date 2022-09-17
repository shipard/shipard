<?php

namespace e10doc\reporting\libs;

use \Shipard\Form\TableForm, \Shipard\Form\Wizard;


/**
 * class CalcReportGenerateRowInvoiceOutWizard
 */
class CalcReportGenerateRowInvoiceOutWizard extends Wizard
{
	var $tableCalcReports;
	var $tableCalcReportsResults;
	var $calcReportResultNdx = 0;
	var $calcReportResultRecData;
	var $calcReportType;
  var $calcReportTypeCfg;

	function init()
	{
		$this->calcReportResultNdx = ($this->focusedPK) ? $this->focusedPK : $this->recData['calcReportResultNdx'];

    $this->tableCalcReports = $this->app()->table('e10doc.reporting.calcReports');
    $this->tableCalcReportsResults = $this->app()->table('e10doc.reporting.calcReportsResults');
		$this->calcReportResultRecData = $this->tableCalcReportsResults->loadItem($this->calcReportResultNdx);
    $this->calcReportRecData = $this->tableCalcReports->loadItem($this->calcReportResultRecData['report']);
    $this->calcReportTypeCfg = $this->app()->cfgItem('e10doc.reporting.calcReports.'.$this->calcReportRecData['calcReportType'], NULL);
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->generateDoc();
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
		$this->recData['calcReportResultNdx'] = $this->focusedPK;

		$this->openForm ();
			$this->addInput('calcReportResultNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function generateDoc ()
	{
		$this->init();

		$crdgEngine = $this->app()->createObject($this->calcReportTypeCfg['rowInvoiceOutGenerator']);
		$crdgEngine->setCalcReportResult($this->calcReportResultNdx);
		$crdgEngine->generateDoc();

		$this->stepResult ['close'] = 1;
    $this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'icon-refresh';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vystavit fakturu za '.$this->calcReportResultRecData['title']];
		//$hdr ['info'][] = ['class' => 'info', 'value' => $this->calcReportTypeCfg['rowInvoiceOutGenerator']];

		return $hdr;
	}
}
