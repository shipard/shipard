<?php

namespace e10pro\hosting\server\libs;


use \e10\TableForm;


/**
 * Class DomainSendChangesWizard
 * @package e10pro\hosting\server\libs
 */
class DomainSendChangesWizard extends \E10\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->stepResult['lastStep'] = 1;
			if ($this->sendChanges())
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
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleWizard');

		$this->openForm (self::ltNone);
			$this->addInput('placebo', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

			$changesEngine = new \e10pro\hosting\server\libs\DomainsChangesEngine($this->app());
			$changesEngine->init();
			$changesEngine->loadChanges();
			if ($changesEngine->changesTable && count($changesEngine->changesTable))
			{
				$this->addContent([[
					'type' => 'table', 'table' => $changesEngine->changesTable, 'header' => $changesEngine->changesHeader,
				]], 0, FALSE, 0);
			}

		$this->closeForm ();
	}

	protected function sendChanges ()
	{
		$changesEngine = new \e10pro\hosting\server\libs\DomainsChangesEngine($this->app());
		$changesEngine->init();
		$changesEngine->loadChanges();
		$changesEngine->sendChanges();

		return TRUE;
	}

	public function createHeader ()
	{
		$hdr = ['icon' => 'icon-send'];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Odeslat změny v DNS'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Uvedené změny v záznamech budou odeslány do DNS'];

		return $hdr;
	}
}
