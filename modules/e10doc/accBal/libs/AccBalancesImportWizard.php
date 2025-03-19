<?php

namespace e10doc\accBal\libs;

use \Shipard\Form\TableForm, \Shipard\Form\Wizard, \Shipard\Utils\Json;
use \Shipard\Utils\Utils;

/**
 * class AccBalancesImportWizard
 */
class AccBalancesImportWizard extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber === 2)
		{
			$this->doIt();
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
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Soubor', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'formText'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addInputFiles();
				$this->closeTab ();

				$this->openTab (self::ltNone);
					$this->addInputMemo('text', NULL, self::coFullSizeY);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ('padd5');
	}

	public function renderFormPreview ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Soubor', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'formText'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->checkImport();
					if (count($this->messagess))
					{
						$c = "<ul 'e10-addwiz-msgs'>";
						forEach ($this->messagess as $m)
							$c .= "<li>" . Utils::es ($m['text']) . '</li>';

						$c .= '</ul>';
						$this->appendCode ($c);
						$this->stepResult ['lastStep'] = 1;
					}
				$this->closeTab ();
				$this->openTab (self::ltNone);
					$this->addInputMemo('jsonConfiguration', NULL, self::coFullSizeY);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ('padd5');
	}

	protected function checkImport()
	{
		$first = 1;
		// -- uploaded files
		forEach ($this->recData ['uploadedFiles'] as $oneFile)
		{
			$fn = __APP_DIR__ .'/'.$oneFile;
			$textData = file_get_contents($fn);

			$errorMsg = '';
			$idm = new \e10doc\accBal\libs\ImportJsonBalanceEngine($this->app());
			if (!$idm->setCfgText($textData, $errorMsg))
			{
				$this->addMessage($errorMsg);
				return FALSE;
			}

			foreach ($idm->cfgData['balances'] as $b)
			{
				if ($first)
					$this->addStatic('Můžete vybrat, která saldokonta chcete naimportovat:');
				$this->addCheckBox('balance-'.$b['globalId'], $b['fullName'], '1', self::coRightCheckbox);
				$this->recData['balance-'.$b['globalId']] = 1;

				$first = 0;
			}

			$this->recData['jsonConfiguration'] = $textData;
			unlink ($fn);
			return TRUE;
		}

		return FALSE;
	}

	public function doIt ()
	{
		$cfgText = $this->recData['jsonConfiguration'];

		$errorMsg = '';
		$idm = new \e10doc\accBal\libs\ImportJsonBalanceEngine($this->app());
		if (!$idm->setCfgText($cfgText, $errorMsg))
		{
			$this->addMessage($errorMsg);
			return;
		}

		$idm->import();

		// -- close wizard
		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'tables/e10doc.accBal.balances';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Importovat nastavení saldokont ze souboru'];

		return $hdr;
	}
}
