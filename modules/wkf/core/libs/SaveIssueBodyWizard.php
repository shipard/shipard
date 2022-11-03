<?php
namespace wkf\core\libs;
use \Shipard\Form\Wizard;
use \e10\base\libs\UtilsBase;


/**
 * class SaveIssueBodyWizard
 */
class SaveIssueBodyWizard extends Wizard
{
  /** @var \wkf\core\TableIssues */
	var $tableIssues;
	var $issueNdx = 0;
	var $issueRecData;

	function init()
	{
		$this->tableIssues = $this->app()->table('wkf.core.issues');

    $this->issueNdx = intval($this->app->testGetParam('focusedPK'));
    if (!$this->issueNdx && isset($this->recData['issueNdx']))
      $this->issueNdx = $this->recData['issueNdx'];

    $this->issueRecData = $this->tableIssues->loadItem($this->issueNdx);

		if (!isset($this->recData['issueNdx']))
			$this->recData['issueNdx'] = $this->issueNdx;
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

		$this->atts = UtilsBase::loadAttachments ($this->app(), [$this->issueNdx], 'wkf.core.issues');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('issueNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 20);

      $this->closeForm ();
	}

	public function send ()
	{
		$this->init();

    $sibe = new \wkf\core\libs\SaveIssueBodyEngine($this->app());
    $sibe->setIssueNdx($this->issueNdx);
    $sibe->run();

 		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'system/actionSplit';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Uložit zorávu do PDF'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->issueRecData['subject']];

		return $hdr;
	}
}
