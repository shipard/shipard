<?php

namespace e10doc\core\libs;


class CreateDocumentWizard extends \Shipard\Form\Wizard
{
	protected $rows = array();
	protected $docActionInfo = array();

	public function doStep ()
	{
		if ($this->pageNumber === 0)
		{
			$this->recData['postData'] = json_encode($this->postData);
			$this->parseDocData ($this->postData);
		}
		if ($this->pageNumber === 1)
		{
			$this->saveDocument();
			$this->stepResult['lastStep'] = 1;
		}
		if ($this->pageNumber === 2)
		{
			$this->stepResult ['close'] = 1;
			$this->stepResult['lastStep'] = 1;
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormResult (); break;
			case 2: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltNone);

		$c = '';
		$c .= "<div class='docRecapitulation' style='padding: 2em; min-width: 40em;'>";
		$c .= "<h1 style='padding-bottom: .6em; text-align: right;'>" . utils::es ($this->welcomeHeader ()) . '</h1>';

		$h = array ('#' => '#', 'symbol1' => 'Výkup', 'date' => 'datum', 'price' => ' Cena/jed');
		$c .= \E10\renderTableFromArray ($this->rows, $h);
		$c .= '</div>';

		$this->appendCode($c);

		$this->addInput('postData', 'ABC', self::INPUT_STYLE_STRING, TableForm::coHidden, 8000);

		$this->closeForm ();
	}

	public function renderFormResult ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltNone);
			$this->appendCode("HOTOVO.");
			$this->addInput('postData', 'ABC', self::INPUT_STYLE_STRING, TableForm::coHidden, 8000);
		$this->closeForm ();
	}

	protected function parseDocData ($docData)
	{
		$rows = array ();
		forEach ($docData as $ddId => $ddValue)
		{
			$parts = explode ('.', $ddId);
			if ($parts[0] !== 'docActionData')
				continue;
			if (isset ($parts[1]) && $parts[1] === 'rows')
			{
				$rows [$parts[2]][$parts[3]] = $ddValue;
				continue;
			}

			$this->docActionInfo[$parts[1]] = $ddValue;
		}

		forEach ($rows as $r)
		{
			if (!isset ($r['enabled']))
				continue;
			$this->rows[] = $r;
		}
	}

	protected function saveDocument ()
	{
		$this->postData = json_decode ($this->recData['postData'], TRUE);
		$this->parseDocData ($this->postData);
	}

	protected function welcomeHeader ()
	{
		return '';
	}

} // CreateDocumentWizard

