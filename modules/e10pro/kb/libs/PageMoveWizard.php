<?php

namespace e10pro\kb\libs;
use e10\TableForm, e10\Wizard;


/**
 * Class PageMoveWizard
 * @package e10pro\kb\libs
 */
class PageMoveWizard extends Wizard
{
	var $tableTexts;
	var $srcPageRecData = NULL;

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
		$this->recData['srcPageNdx'] = $this->app->testGetParam ('focusedPK');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (self::ltVertical);
			$this->addInput('srcPageNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

			$treeDef = ['objectId' => 'e10pro.kb.libs.WikiContentInput'];
			$treeTitle = ['text' => 'Vyberte, kam chcete stránku přesunout', 'class' => 'h2', 'icon' => 'icon-crosshairs'];
			$this->addInputTree ('moveDestination', $treeTitle, $treeDef);
		$this->closeForm ();
	}

	public function doIt ()
	{
		$this->loadSettings ();

		// -- do it
		$pme = new \e10pro\kb\libs\PageMoveEngine($this->app());
		$pme->movePage(intval($this->recData['srcPageNdx']), $this->recData['moveDestination']);

		// -- close wizard
		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$this->loadSettings ();

		$hdr = [];
		$hdr ['icon'] = 'icon-files-o';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přesunout wiki stránku'];

		if ($this->srcPageRecData)
		{
			$hdr ['info'][] = ['class' => 'info', 'value' => ['text' => $this->srcPageRecData['title'], 'icon' => 'icon-globe']];
		}

		return $hdr;
	}

	protected function loadSettings ()
	{
		$this->tableTexts = $this->app()->table ('e10pro.kb.texts');
		$this->srcPageRecData = $this->tableTexts->loadItem ($this->recData['srcPageNdx']);
	}
}
