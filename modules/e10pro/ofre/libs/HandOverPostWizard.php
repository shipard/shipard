<?php

namespace e10pro\ofre\libs;
use \Shipard\Form\Wizard;


/**
 * class HandOverPostWizard
 */
class HandOverPostWizard extends Wizard
{
  /** @var \wkf\core\TableIssues */
	var $tableIssues;
	var $workOrderNdx = 0;
	var $workOrderRecData;

	var \e10pro\ofre\libs\OfficeInfo $officeInfo;

	function init()
	{
		$this->tableIssues = $this->app()->table('wkf.core.issues');
		$this->workOrderNdx = ($this->focusedPK) ? $this->focusedPK : $this->recData['workOrderNdx'];
		$this->workOrderRecData = $this->app()->loadItem($this->workOrderNdx, 'e10mnf.core.workOrders');

		if (!isset($this->recData['workOrderNdx']))
			$this->recData['workOrderNdx'] = $this->workOrderNdx;
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->send();
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

		//$this->atts = UtilsBase::loadAttachments ($this->app(), [$this->issueNdx], 'wkf.core.issues');

    $this->officeInfo = new \e10pro\ofre\libs\OfficeInfo($this->app());
    $this->officeInfo->setWorkOrder($this->workOrderNdx);
    $this->officeInfo->loadIssues();


		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (self::ltVertical);
			$this->addInput('workOrderNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 20);

			if (isset($this->officeInfo->data['issues']))
			{
				$this->addStatic([
					'type' => 'table',
					'table' => $this->officeInfo->data['issues']['table'],
					'header' => $this->officeInfo->data['issues']['header'],
					'params' => ['hideHeader' => 1, 'tableClass' => 'mb1 padd5'],
				]);
			}

		$this->closeForm ();
	}

	public function send ()
	{
		$this->init();

    $e = new \e10pro\ofre\libs\HandOverPostEngine($this->app());
    $e->setWorkOrderNdx($this->workOrderNdx);
    $e->run();

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'system/actionDownload';

    $title = $this->workOrderRecData['title'];
    if ($this->workOrderRecData['title'] == '')
    {
      $personRecData = $this->app()->loadItem($this->workOrderRecData['customer'], 'e10.persons.persons');
      if ($personRecData)
        $title = $personRecData['fullName'];
    }

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Předat poštu'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $title];

		return $hdr;
	}
}
