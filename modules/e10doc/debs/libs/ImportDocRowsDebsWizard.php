<?php

namespace e10doc\debs\libs;
use \e10\TableForm;


/**
 * Class NodeServerCfgWizard
 * @package mac\lan\libs
 */
class ImportDocRowsDebsWizard extends \E10\Wizard
{
	var $srcDocNdx = 0;
	var $srcDocRecData = NULL;
	var $srcDocErrors = [];

	/** @var \e10doc\debs\libs\ImportDocRowsDebsEngine */
	var $importEngine;

	var $previewContent = [];


	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{

		}
		elseif ($this->pageNumber === 2)
		{
			$this->stepResult['lastStep'] = 1;
			if ($this->doImport())
			{
				$this->stepResult ['close'] = 1;
			}
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormPreview (); break;
			case 2: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->detectSrcDoc();

		$this->setFlag ('formStyle', 'e10-formStyleWizard');
		$this->setFlag ('maximize', 1);

		$this->openForm (self::ltNone);
			if (count($this->srcDocErrors))
				$this->addStatic($this->srcDocErrors);
			else
				$this->addInputFiles();
			$this->addInput('srcDocNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
		$this->closeForm ();
	}

	public function renderFormPreview()
	{
		$this->createPreview();

		$this->setFlag ('formStyle', 'e10-formStyleWizard');

		$this->openForm (self::ltNone);
			$this->addInput('srcDocNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
			$this->addInput('importFiles', '', self::INPUT_STYLE_STRING, self::coHidden, 240);
			//$this->addStatic('TEST: '.json_encode($this->importEngine->dstDataRows));
			$this->addContent($this->previewContent);
		$this->closeForm ();
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'system/actionDownload'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Import účetní dávky'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Vyberte .CSV soubor k importu'];

		return $hdr;
	}

	function detectSrcDoc()
	{
		$this->srcDocNdx = intval($this->app->testGetParam('pk'));
		$this->recData['srcDocNdx'] = strval($this->srcDocNdx);
		if (!$this->srcDocNdx)
		{
			$this->srcDocErrors[] = ['text' => 'CHYBA: Doklad neexistuje.', 'class' => 'block e10-error'];
			return;
		}

		$tableHeads = $this->app()->table('e10doc.core.heads');
		$this->srcDocRecData = $tableHeads->loadItem ($this->srcDocNdx);

		if (!$this->srcDocRecData)
		{
			$this->srcDocErrors[] = ['text' => 'CHYBA: Doklad nelze načíst.', 'class' => 'block e10-error'];
			return;
		}
		if ($this->srcDocRecData['docState'] !== 1000 && $this->srcDocRecData['docState'] !== 8000)
		{
			$this->srcDocErrors[] = ['text' => 'Doklad je uzavřen. Importovat lze pouze otevřené doklady.', 'class' => 'block e10-error'];
		}
	}

	function createPreview()
	{
		$this->importEngine = new \e10doc\debs\libs\ImportDocRowsDebsEngine($this->app());
		$this->importEngine->init();
		$this->importEngine->setFileNames($this->recData ['uploadedFiles']);
		$this->importEngine->parse();

		$this->previewContent = [];

		$this->recData['importFiles'] = json_encode($this->recData ['uploadedFiles']);

		foreach ($this->importEngine->dstDataRows as $fileName => $rows)
		{
			$this->previewContent[] = [
				'type' => 'table', 'table' => $rows, 'header' => $this->importEngine->previewTableHeader,
				'title' => $fileName,
			];
		}
	}

	function doImport()
	{
		$srcDocNdx = intval($this->recData['srcDocNdx']);

		$importFiles = json_decode($this->recData['importFiles'], TRUE);

		$this->importEngine = new \e10doc\debs\libs\ImportDocRowsDebsEngine($this->app());
		$this->importEngine->init();
		$this->importEngine->setDstDocNdx($srcDocNdx);
		$this->importEngine->setFileNames($importFiles);
		$this->importEngine->parse();
		$this->importEngine->import();

		return TRUE;
	}
}
