<?php
namespace e10doc\bank\libs;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';



class AddWizard extends \Shipard\Form\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->saveItem();
		}
		if ($this->pageNumber == 2)
		{
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

			$focusedPK = intval ($this->app()->testGetParam('focusedPK'));
			if ($focusedPK !== 0)
				$this->addCheckBox('replaceDocumentNdx', 'Přepsat aktuálně označený bankovní výpis', $focusedPK, 0);

			$tabs ['tabs'][] = array ('text' => 'Soubor', 'icon' => 'system/formHeader');
			$tabs ['tabs'][] = array ('text' => 'Text', 'icon' => 'formText');
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
				$this->addInputFiles();
			$this->closeTab ();

			$this->openTab ();
				$this->addInputMemo('text', NULL, self::coFullSizeY);
			$this->closeTab ();

      $this->closeTabs ();
		$this->closeForm ();
	}

	public function saveItem ()
	{
		// -- uploaded files
		forEach ($this->recData ['uploadedFiles'] as $oneFile)
		{
			$fn = __APP_DIR__ .'/'.$oneFile;
			$textData = file_get_contents($fn);
			$import = \E10Doc\Bank\createImportObject ($this->app, $textData);
			if ($import)
			{
				if (isset ($this->recData['replaceDocumentNdx']))
				{
					error_log ("REPLACE DOC: " . json_encode ($this->recData['replaceDocumentNdx']));
					$import->setReplaceDocumentNdx (intval ($this->recData['replaceDocumentNdx']));
				}
				$import->run ();
				if (isset($import->docHead['ndx']) && $import->docHead['ndx'])
					\E10\Base\addAttachments ($this->app, 'e10doc.core.heads', $import->docHead['ndx'], $fn, '', FALSE);
				$this->addMessage ($import->messagess());
			}
			else
			{
				$this->addMessage ("Soubor '$oneFile' neodpovídá žádnému ze známých formátů bankovního výpisu.");
			}
			unlink ($fn);
		}

		if ($this->messagess () === FALSE)
			$this->stepResult ['close'] = 1;
	}
}

