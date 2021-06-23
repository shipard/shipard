<?php

namespace mac\access\libs;

use e10\TableForm, e10\Wizard, e10\str;


/**
 * Class ConnectSocketWizard
 * @package mac\lan\libs
 */
class WizardAddTagsBatch extends Wizard
{
	/** @var \mac\access\TableTags */
	var $tableTags;


	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->doIt();
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
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->initInfo();
		$this->recData['tagType'] = 1;

		$enumTT = [];
		foreach ($this->app()->cfgItem('mac.access.tagTypes') as $ttId => $tt)
			$enumTT[$ttId] = $tt['name'];

		$this->openForm ();
			$this->addInputEnum2('tagType', 'Druh klíče', $enumTT, TableForm::INPUT_STYLE_OPTION);
			$this->layoutOpen(self::ltHorizontal);
				$this->addInputMemo ('tagsList', 'Seznam klíčů; každý klíč na nový řádek; za středníkem může být poznámka (12345678; Jan Novák)');
			$this->layoutClose('pa2');
		$this->closeForm ();
	}

	public function doIt ()
	{
		$this->initInfo();

		$tagType = intval($this->recData['tagType']);

		$rows = preg_split("/\\r\\n|\\r|\\n/", $this->recData['tagsList']);
		foreach ($rows as $row)
		{
			$parts = explode(';', $row);
			if (count($parts) < 1)
				continue;

			$tagValue = trim(array_shift($parts));

			if ($tagType === 1)
			{
				$tagValueScanner = str::scannerString($tagValue);
				if (str::strlen($tagValue) == str::strlen($tagValueScanner) && $tagValueScanner == intval($tagValueScanner))
					$tagValue = $tagValueScanner;
			}

			$tagNote = '';
			if (isset($parts[0]))
				$tagNote = trim(implode(';', $parts));

			$newItem = [
				'tagType' => $tagType, 'id' => '',
				'keyValue' => $tagValue, 'note' => $tagNote,
				'docState' => 4000, 'docStateMain' => 2,
			];

			$newNdx = $this->tableTags->dbInsertRec($newItem);
			$this->tableTags->docsLog($newNdx);
		}

		// -- close wizard
		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'iconAddInBulk';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Hromadné přidání klíčů'];
		$hdr ['info'][] = ['class' => 'info', 'value' => ['text' => 'Nové klíče budou přidány k použití, ale nebudou nikomu přiřazeny', 'icon' => 'icon-plus']];

		return $hdr;
	}

	function initInfo()
	{
		$this->tableTags = $this->app()->table ('mac.access.tags');
	}
}
