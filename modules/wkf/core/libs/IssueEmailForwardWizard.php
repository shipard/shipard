<?php
namespace wkf\core\libs;
use \Shipard\Form\Wizard, \Shipard\Utils\Json;


/**
 * class IssueEmailForwardWizard
 */
class IssueEmailForwardWizard extends Wizard
{
  /** @var \wkf\core\TableIssues */
	var $tableIssues;
	var $issueNdx = 0;
	var $issueRecData;

	function init()
	{
		$this->tableIssues = $this->app()->table('wkf.core.issues');
		$this->issueNdx = ($this->focusedPK) ? $this->focusedPK : $this->recData['issueNdx'];
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

    $ife = new \wkf\core\libs\IssueEmailForwardEngine($this->app());
    $ife->setIssueNdx($this->issueNdx);

    $this->recData['emailsTo'] = $ife->emailsTo;
    $this->recData['subject'] = $ife->subject;
    $this->recData['body'] = $ife->body;

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('issueNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);

			$this->layoutOpen(self::ltGrid);
				$this->addInput('subject', 'Předmět', self::INPUT_STYLE_STRING, self::coColW12, 100);
				$this->addInput('emailsTo', 'Pro', self::INPUT_STYLE_STRING, self::coColW12, 120);
				$this->addInputMemo('body', 'Text zprávy', self::coColW12);
			$this->layoutClose();

		$this->closeForm ();
	}

	public function send ()
	{
		$this->init();

    $ife = new \wkf\core\libs\IssueEmailForwardEngine($this->app());
    $ife->setIssueNdx($this->issueNdx);

    $ife->emailsTo = $this->recData['emailsTo'];
    $ife->subject = $this->recData['subject'];
    $ife->body = $this->recData['body'];

    $ife->send();

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'user/envelope';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přeposlat zprávu: '.$this->issueRecData['subject']];
		//$hdr ['info'][] = ['class' => 'info', 'value' => Json::encode($this->requestData['person']['login'])];

		return $hdr;
	}
}
