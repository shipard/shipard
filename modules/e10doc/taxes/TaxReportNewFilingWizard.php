<?php

namespace e10doc\taxes;

use \e10\utils, \e10\Utility, \e10\TableForm, \e10\Wizard;


/**
 * Class TaxReportNewFilingWizard
 * @package e10doc\taxes
 */
class TaxReportNewFilingWizard extends Wizard
{
	var $tableReports;
	var $taxReportNdx = 0;
	var $taxReportRecData;
	var $taxReportType;

	function init()
	{
		$this->tableReports = $this->app()->table('e10doc.taxes.reports');
		$this->taxReportNdx = ($this->app()->testGetParam('taxreport') !== '') ? $this->app()->testGetParam('taxreport') : $this->recData['taxReportNdx'];
		$this->taxReportRecData = $this->tableReports->loadItem($this->taxReportNdx);
		$this->taxReportType = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->taxReportRecData['reportType'], NULL);
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->newFiling();
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
		$this->init();

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->recData['taxReportNdx'] = $this->taxReportNdx;
		$filingTypes = $this->tableReports->filingTypesEnum($this->taxReportRecData['reportType']);
		$this->recData['filingType'] = key($filingTypes);

		$this->openForm ();
			$this->addInput('taxReportNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInputEnum2('filingType', 'Druh podání', $filingTypes, TableForm::INPUT_STYLE_RADIO);
		$this->closeForm ();
	}

	public function newFiling ()
	{
		$this->init();

		$trEngine = $this->app()->createObject($this->taxReportType['filingEngine']);
		$trEngine->init();
		$trEngine->createFiling($this->recData);

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'icon-refresh';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Nové podání: '.$this->taxReportType['name']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->taxReportRecData['title']];

		return $hdr;
	}
}
