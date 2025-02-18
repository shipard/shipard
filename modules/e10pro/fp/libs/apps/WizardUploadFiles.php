<?php

namespace e10pro\fp\libs\apps;
use \Shipard\Form\Wizard, \Shipard\Form\TableForm;
use \Shipard\Application\DataModel;


/**
 * class WizardUploadFiles
 */
class WizardUploadFiles extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->uploadFiles();
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
		if (!isset($this->recData['uploadUrl']))
			$this->recData['uploadUrl'] = $this->requestParams['upload-url'] ?? '';

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addDataInput('uploadUrl', ['type' => DataModel::ctString, 'len' => 100, 'name' => 'URL pro upload'], /*TableForm::coHidden*/0);

			$this->addInputFiles(['upload-url' => $this->recData['uploadUrl']]);
		$this->closeForm ();
	}

	public function uploadFiles ()
	{
		/*
		error_log("### CREATE-FOLDER: " . json_encode($this->recData));
		$filesEngine = new \e10pro\fp\libs\FilesEngine ($this->app());

		$filesEngine->setFilePortal(intval($this->recData['filePortalNdx']));
		$filesEngine->setStorage(intval($this->recData['storageNdx']));
		$result = $filesEngine->createNewFolder($this->recData['activeFolder'], $this->recData['folderName']);

		if (!$result)
			$this->stepResult ['close'] = 1;
		*/

		//$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['AHOJ'] = 12345;
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
