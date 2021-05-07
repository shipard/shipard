<?php


namespace lib\wkf;
require_once (__APP_DIR__ . '/e10-modules/e10/base/base.php');

use e10\Wizard, e10pro\wkf\TableMessages, e10\utils;


/**
 * Class WizardAddFromAttachments
 * @package lib\wkf
 */
class WizardAddFromAttachments extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->saveItems();
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
		$this->setFlag ('maximize', 1);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

			$this->openForm ();
				$this->addInput('placebo', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
			$this->addInputFiles();

		$this->closeForm ();
	}

	public function saveItems ()
	{
		$tableMessages = $this->app->table ('e10pro.wkf.messages');

		forEach ($this->recData ['uploadedFiles'] as $oneFile)
		{
			$fn = __APP_DIR__ .'/'.$oneFile;

			$msgTypeNdx = TableMessages::mtInbox;
			$msgKindNdx = $tableMessages->msgKindDefault ($msgTypeNdx, TRUE);

			$msgRecData = ['msgType' => $msgTypeNdx, 'msgKind' => $msgKindNdx, 'source' => 0, 'docState' => 1000, 'docStateMain' => 0];

			$tableMessages->checkNewRec ($msgRecData);
			$newMsgNdx = $tableMessages->dbInsertRec ($msgRecData);
			\E10\Base\addAttachments ($this->app, 'e10pro.wkf.messages', $newMsgNdx, $fn, '', FALSE);
			$tableMessages->docsLog ($newMsgNdx);
			unlink ($fn);
		}

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'icon-inbox'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přidat došlé pošty z příloh'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Každý soubor přílohy bude přidán jako jedna nová došlá pošta'];

		return $hdr;
	}
}
