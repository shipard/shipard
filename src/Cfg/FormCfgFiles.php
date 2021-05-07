<?php
// TODO: remove file?

namespace Shipard\Cfg;
use \Shipard\Form\TableForm;


class FormCfgFiles extends TableForm
{
	public function renderForm ()
	{
		$this->openForm ();
			$this->addInputMemo ("text", "Text", TableForm::coFullSizeY);
		$this->closeForm ();
	}

	public function createHeaderCode ()
	{
		return $this->defaultHedearCode ("e10-server-config", basename($this->recData['fileName']), '');
	}
}

