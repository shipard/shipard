<?php

namespace e10doc\taxes\VatReturn;
use \Shipard\Form\Wizard;


/**
 * class VatReturnAccWizard
 */
class VatReturnAccWizard extends Wizard
{
	public function __construct($app, $options = NULL)
	{
		parent::__construct($app, $options);
		$this->dirtyColsReferences['vatPeriod'] = 'e10doc.base.taxperiods';
	}

	public function createHeader ()
	{
		$hdr = array ();
		$hdr ['icon'] = 'system/actionRegenerate';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Zaúčtování DPH'];
		
		$taxReport = $this->app()->loadItem ($this->recData['taxReportNdx'], 'e10doc.taxes.reports');
		$hdr ['info'][] = ['class' => 'info', 'value' => $taxReport['title']];

		$filing = $this->app()->loadItem ($this->recData['filingNdx'], 'e10doc.taxes.filings');
		$hdr ['info'][] = ['class' => 'info', 'value' => $filing['title']];

		return $hdr;
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt ();
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
		$taxReportNdx = intval($this->app()->testGetParam('taxReportNdx'));
		$this->recData['taxReportNdx'] = $taxReportNdx;

		$filingNdx = intval($this->app()->testGetParam('filingNdx'));
		$this->recData['filingNdx'] = $filingNdx;

		$this->table = $this->app->table ('e10doc.base.taxperiods');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addInput ('taxReportNdx', 'Přiznání', self::INPUT_STYLE_STRING, self::coHidden);
			$this->addInput ('filingNdx', 'Podání', self::INPUT_STYLE_STRING, self::coHidden);
		$this->closeForm ();
	}

	public function doIt ()
	{
		$eng = new \e10doc\taxes\VatReturn\VatReturnAccEngine ($this->app);
		if ($this->recData['filingNdx'])
		{
			$eng->setParams($this->recData['filingNdx']);
			$eng->run();
		}

		$this->stepResult ['close'] = 1;
	}
}
