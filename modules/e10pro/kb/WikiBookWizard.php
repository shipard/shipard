<?php

namespace e10pro\kb;

use e10\utils, e10\Wizard, e10\TableForm;


/**
 * Class WikiBookWizard
 * @package e10pro\kb
 */
class WikiBookWizard extends Wizard
{
	var $tableSections;
	var $section = NULL;
	var $bg = NULL;

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->generateBook();
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

	public function addParams ()
	{
		$genBookId = $this->app->testGetParam('focusedPK');

		if ($genBookId !== '')
			$this->recData['genBookId'] = $genBookId;

		$this->addInput('genBookId', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
	}

	public function renderFormWelcome ()
	{
		$this->recData['lanNdx'] = $this->focusedPK;

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addParams();
		$this->closeForm ();
	}

	public function renderFormDone ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->openForm ();

		$c = '';
		if (count($this->messagess))
		{
			$c .= "<ul 'e10-addwiz-msgs'>";
			forEach ($this->messagess as $m)
			{
				$c .= "<li>" . utils::es ($m['text']) . '</li>';
			}
			$c .= '</ul>';
		}
		$c .= "<a href='{$this->app->dsRoot}/{$this->bg->bookFiles[0]['url']}' class='btn btn-primary' target='_blank'>Stáhnout</a>";

		$this->appendCode ($c);

		$this->closeForm ();
	}

	public function generateBook ()
	{
		$this->loadSettings();

		$this->bg = new \lib\documentation\BookGenerator($this->app());

		$this->bg->addSrcBookSection($this->section['ndx']);

		$this->bg->generateBook();

		$this->stepResult ['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$this->loadSettings ();

		$hdr = [];
		$hdr ['icon'] = 'icon-book';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vygenerovat knihu'];


		if ($this->section)
		{
			$hdr ['info'][] = ['class' => 'info', 'value' => ['text' => $this->section['title'], 'icon' => 'icon-file']];
		}

		return $hdr;
	}

	protected function loadSettings ()
	{
		$this->tableSections = $this->app()->table ('e10pro.kb.sections');

		$pp = explode ('-', $this->recData['genBookId']);
		if ($pp[0] === 's')
		{
			$this->section = $this->tableSections->loadItem($pp[1]);
		}
	}
}
