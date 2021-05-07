<?php

namespace e10\web;

use e10\TableForm, e10\Wizard;


/**
 * Class CloneWebServerWizard
 * @package e10\web
 */
class CloneWebServerWizard extends Wizard
{
	var $tableServers;
	var $serverRecData = NULL;

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
		$this->recData['serverNdx'] = $this->app->testGetParam ('pk');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('serverNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function doIt ()
	{
		$this->loadSettings ();
		$this->tableServers->copyServer ($this->recData['serverNdx']);

		// -- close wizard
		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$this->loadSettings ();

		$hdr = [];
		$hdr ['icon'] = 'icon-files-o';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vytvořit kopii webového serveru'];

		if ($this->serverRecData)
		{
			$hdr ['info'][] = ['class' => 'info', 'value' => ['text' => $this->serverRecData['fullName'], 'icon' => 'icon-globe']];
		}

		return $hdr;
	}

	protected function loadSettings ()
	{
		$this->tableServers = $this->app()->table ('e10.web.servers');
		$this->serverRecData = $this->tableServers->loadItem ($this->recData['serverNdx']);
	}
}
