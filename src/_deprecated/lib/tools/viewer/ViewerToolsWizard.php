<?php

namespace lib\tools\viewer;


use E10Doc\Core\e10utils, \E10\uiutils, \E10\TableForm, \E10\Wizard;


/**
 * Class ViewerToolsWizard
 * @package lib\tools\viewer
 */
class ViewerToolsWizard extends Wizard
{
	protected $actionClass = NULL;
	protected $actionObject = NULL;
	protected $tableTasks;

	protected function init ()
	{
		$this->actionObject = $this->app()->createObject($this->actionClass);
		$this->tableTasks = $this->app()->table('e10.base.tasks');
	}

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->doAction();
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
		$this->recData['myBankAccount'] = $this->app->testGetParam('__myBankAccount');
		$this->addInput('myBankAccount', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);


		foreach ($_GET as $param => $value)
		{
			if (substr($param, 0, 11) !== 'data-param-')
				continue;
			$this->recData[$param] = $value;
			$this->addInput($param, '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		}
	}

	public function renderFormWelcome ()
	{
		$this->init();
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addParams();
		$this->closeForm ();
	}

	public function createHeader ()
	{
		$info = $this->actionObject->actionInfo ();

		$hdr = [];
		if (isset($info['icon']))
			$hdr ['icon'] = $info['icon'];

		if (isset($info['name']))
			$hdr ['info'][] = ['class' => 'title', 'value' => $info['name']];

		return $hdr;
	}

	public function doAction ()
	{
		$this->init();
		$this->stepResult ['close'] = 1;

		$this->actionObject->setParams ($this->recData);
		$this->actionObject->init();
		$this->actionObject->run ();
	}
}

