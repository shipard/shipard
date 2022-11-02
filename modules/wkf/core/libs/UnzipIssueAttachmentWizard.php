<?php
namespace wkf\core\libs;
use \Shipard\Form\Wizard;
use \e10\base\libs\UtilsBase;


/**
 * class UnzipIssueAttachmentWizard
 */
class UnzipIssueAttachmentWizard extends Wizard
{
  /** @var \wkf\core\TableIssues */
	var $tableIssues;
	var $issueNdx = 0;
	var $issueRecData;

  var $attachmentNdx = 0;
  var $attachmentRecData;


  var $atts = NULL;


	function init()
	{
		$this->tableIssues = $this->app()->table('wkf.core.issues');

    $this->issueNdx = intval($this->app()->testGetParam('issueNdx'));
    if (!$this->issueNdx && isset($this->recData['issueNdx']))
      $this->issueNdx = $this->recData['issueNdx'];

    $this->attachmentNdx = intval($this->app()->testGetParam('attachmentNdx'));
    if (!$this->attachmentNdx && isset($this->recData['attachmentNdx']))
      $this->attachmentNdx = $this->recData['attachmentNdx'];

    $this->issueRecData = $this->tableIssues->loadItem($this->issueNdx);

    $this->attachmentRecData = $this->app()->loadItem($this->attachmentNdx, 'e10.base.attachments');

		if (!isset($this->recData['issueNdx']))
			$this->recData['issueNdx'] = $this->issueNdx;
    if (!isset($this->recData['attachmentNdx']))
			$this->recData['attachmentNdx'] = $this->attachmentNdx;
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
      $this->addInput('attachmentNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 20);

      $ffn = __APP_DIR__.'/att/'.$this->attachmentRecData['path'].''.$this->attachmentRecData['filename'];

      $this->addStatic(['text' => 'Vyberte soubory z archívu, které chcete přidat jako přílohu:', 'class' => 'h2 mb1 block padd5']);
      $fa = new \lib\core\attachments\FileArchiveExtractor($this->app());
      $fa->setFileName($ffn);
      $fa->getFilesList();

      foreach ($fa->filesList as $oneFile)
      {
        $colId = 'unzip-'.$oneFile['ndx'];
        $this->addCheckBox($colId, $oneFile['fileName'], '1', self::coRightCheckbox);
        $this->recData[$colId] = '1';
      }
      $this->addStatic(['text' => ' ', 'class' => 'block mt1']);
      $this->closeForm ();
	}

	public function send ()
	{
		$this->init();
    $ffn = __APP_DIR__.'/att/'.$this->attachmentRecData['path'].''.$this->attachmentRecData['filename'];

    $filesToExtract = [];
    foreach ($this->recData as $key => $value)
    {
      if (!str_starts_with($key, 'unzip-') || $value != 1)
        continue;

      $fileIdx = intval(substr($key, 6));
      $filesToExtract[] = $fileIdx;
    }

    if (count($filesToExtract))
    {
      $fa = new \lib\core\attachments\FileArchiveExtractor($this->app());
      $fa->setFileName($ffn);
      $fa->extractAsAttachments ('wkf.core.issues', $this->issueNdx, $filesToExtract);

      $this->app()->db()->query('UPDATE [e10_attachments_files] SET [deleted] = %i', 1, ' WHERE [ndx] = %i', $this->attachmentNdx);
    }

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'system/actionSplit';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Rozbalit soubory ze ZIP archívu'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->attachmentRecData['filename']];

		return $hdr;
	}
}
