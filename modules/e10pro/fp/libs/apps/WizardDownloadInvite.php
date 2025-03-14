<?php

namespace e10pro\fp\libs\apps;
use \Shipard\Form\Wizard, \Shipard\Form\TableForm;
use \Shipard\Application\DataModel;


/**
 * class WizardDownloadInvite
 */
class WizardDownloadInvite extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->createFolder();
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
		if (!isset($this->recData['filePortalUId']))
			$this->recData['filePortalUId'] = $this->requestParams['fileportal-uid'] ?? '';
		if (!isset($this->recData['storageUId']))
			$this->recData['storageUId'] = $this->requestParams['storage-uid'] ?? '';
		if (!isset($this->recData['activeFolder']))
			$this->recData['activeFolder'] = $this->requestParams['active-folder'] ?? '';

		if (!isset($this->recData['fileName']))
			$this->recData['fileName'] = $this->requestParams['file-name'] ?? '';

		if (!isset($this->recData['emai']))
			$this->recData['folderName'] = '';

		$storage = $this->app()->db()->query('SELECT * FROM [e10pro_fp_storages] WHERE [uid] = %s', $this->recData['storageUId'])->fetch();
		if ($storage)
			$this->recData['emails'] = $storage['emailForSendDownloads'] ?? '';

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addDataInput('filePortalUId', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'Portál'], TableForm::coHidden);
			$this->addDataInput('storageUId', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'Úložiště'], TableForm::coHidden);
			$this->addDataInput('activeFolder', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'Aktivní složka'], TableForm::coHidden);
			$this->addDataInput('fileName', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'Soubor'], TableForm::coHidden);

			$this->addDataInput('emails', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'E-mail'], TableForm::coFocus);

		$this->closeForm ();
	}

	public function createFolder ()
	{
		$ie = new \e10pro\fp\libs\apps\DownloadInvitesEngine ($this->app());

		$ie->init();
		$ie->createInvite($this->recData);

		$this->stepResult ['close'] = 1;

		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Odeslat výzvu ke stažení'];

		return $hdr;
	}
}
