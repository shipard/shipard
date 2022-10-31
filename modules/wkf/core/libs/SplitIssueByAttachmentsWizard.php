<?php
namespace wkf\core\libs;
use \Shipard\Form\Wizard;
use \e10\base\libs\UtilsBase;


/**
 * class SplitIssueByAttachmentsWizard
 */
class SplitIssueByAttachmentsWizard extends Wizard
{
  /** @var \wkf\core\TableIssues */
	var $tableIssues;
	var $issueNdx = 0;
	var $issueRecData;
  var $atts = NULL;


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

		$this->atts = UtilsBase::loadAttachments ($this->app(), [$this->issueNdx], 'wkf.core.issues');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (self::ltVertical);
			$this->addInput('issueNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);

      $this->addStatic(['text' => 'Z následujích příloh budou vytvořeny jednotlivé zprávy:', 'class' => 'h2 mb1 block']);
      $attLinks = $this->attLinks($this->issueNdx);
      foreach ($attLinks as $att)
      {
        $this->addStatic($att);
      }

		$this->closeForm ();
	}

	public function send ()
	{
		$this->init();

    $sie = new \wkf\core\libs\SplitIssueByAttachmentsEngine($this->app());
    $sie->setIssueNdx($this->issueNdx);
    $sie->run();

		$this->stepResult ['close'] = 1;
	}

	function attLinks ($ndx)
	{
		$links = [];
		$attachments = $this->atts[$ndx];
		if (isset($attachments['images']))
		{
			foreach ($attachments['images'] as $a)
			{
				$icon = ($a['filetype'] === 'pdf') ? 'system/iconFilePdf' : 'system/iconFile';
				$l = ['text' => $a['name'], 'icon' => $icon, 'class' => 'e10-att-link btn btn-xs btn-default df2-action-trigger', 'prefix' => ''];
				$l['data'] =
					[
						'action' => 'open-link',
						'url-download' => $this->app()->dsRoot.'/att/'.$a['path'].$a['filename'],
						'url-preview' => $this->app()->dsRoot.'/imgs/-w1200/att/'.$a['path'].$a['filename'],
						'popup-id' => 'wdbi', 'with-shift' => 'tab' /* 'popup' */
					];
				$links[] = $l;
			}
		}
		if (isset($attachments['files']))
		{
			foreach ($attachments['files'] as $a)
			{
				$icon = 'system/iconFile';
				$l = ['text' => $a['name'], 'icon' => $icon, 'class' => '_label _label-default e10-tag lh16', '___prefix' => ''];
				$links[] = $l;
			}
		}

		return $links;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'system/actionSplit';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vytvořit zprávy s jednotlivými přílohami'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->issueRecData['subject']];

		return $hdr;
	}
}
