<?php

namespace Shipard\Form;


class Window extends \Shipard\Form\TableForm
{
	/** @var \E10\Application */
	var $app;
	var $stepResult;
	var $focusedPK = 0;

	public function app() {return $this->app;}

	public function __construct($app, $options = NULL)
	{
		parent::__construct(NULL, $app->requestPath (2), $app->requestPath (3));
		$this->app = $app;

		$this->focusedPK = intval (Application::testGetParam('focusedPK'));

		$postData = Application::testGetData();
		$this->postData = json_decode ($postData, TRUE);
	}

	public function createToolbarCode ()
	{
		return '';
	}

	public function createHeader ()
	{
		return array ();
	}

	public function finalCode ()
	{
		$this->createCode ();
		return $this->html;
	}

	function openForm ($layoutType = TableForm::ltForm)
	{
		$formStyleClass = $this->flag ('formStyle', 'e10-formStyleDefault');
		$formStyleClass .= ' ' . 'test2';
		$this->stackPush ();

		$h = "<div class='df2-form e10-formControl e10-formWizard $formStyleClass' id='{$this->fid}Container'><div id='{$this->fid}' data-object='wizard'";
		$h .= " data-formid='{$this->formId}'";
		$h .= '>';

		$this->appendCode ($h);
		$this->layoutOpen ($layoutType);
	}

}

