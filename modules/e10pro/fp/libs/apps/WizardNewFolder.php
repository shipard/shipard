<?php

namespace e10pro\fp\libs\apps;
use \Shipard\Form\Wizard, \Shipard\Form\TableForm;
use \Shipard\Application\DataModel;


/**
 * class WizardNewFolder
 */
class WizardNewFolder extends Wizard
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

		if (!isset($this->recData['folderName']))
			$this->recData['folderName'] = '';

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addDataInput('filePortalUId', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'Portál'], TableForm::coHidden);
			$this->addDataInput('storageUId', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'Úložiště'], TableForm::coHidden);
			$this->addDataInput('activeFolder', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'Aktivní složka'], TableForm::coHidden);
			$this->addDataInput('folderName', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'Název složky']);
		$this->closeForm ();
	}

	public function createFolder ()
	{
		$filesEngine = new \e10pro\fp\libs\FilesEngine ($this->app());

		$filesEngine->setFilePortal($this->recData['filePortalUId']);
		$filesEngine->setStorage($this->recData['storageUId']);
		$result = $filesEngine->createNewFolder($this->recData['activeFolder'], $this->recData['folderName']);

		if (!$result)
			$this->stepResult ['close'] = 1;

		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vytvořit novou složku'];

		return $hdr;
	}
}
