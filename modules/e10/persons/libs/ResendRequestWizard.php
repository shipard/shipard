<?php

namespace e10\persons\libs;
use \Shipard\Form\TableForm, \Shipard\Form\Wizard, \Shipard\Utils\Json;


/**
 * class ResendRequestWizard
 */
class ResendRequestWizard extends Wizard
{
	var $tableRequests;
	var $requestNdx = 0;
	var $requestRecData;
	var $requestData;

	function init()
	{
		$this->tableRequests = $this->app()->table('e10.persons.requests');
		$this->requestNdx = ($this->focusedPK) ? $this->focusedPK : $this->recData['requestNdx'];
		$this->requestRecData = $this->tableRequests->loadItem($this->requestNdx);
		$this->requestData = Json::decode($this->requestRecData['requestData']);

		if (!isset($this->recData['destEmail']))
			$this->recData['destEmail'] = $this->requestData['person']['login'];

		if (!isset($this->recData['requestNdx']))
			$this->recData['requestNdx'] = $this->requestNdx;
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->rebuild();
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

		$this->openForm ();
			$this->addInput('requestNdx', '', self::INPUT_STYLE_STRING, /*TableForm::coHidden*/0, 120);
			$this->addInput('destEmail', 'Odeslat na e-mail', self::INPUT_STYLE_STRING, 0, 120);
		$this->closeForm ();
	}

	public function rebuild ()
	{
		$this->init();

		$srEngine = new \e10\persons\libs\SendRequestEngine($this->app());
		$srEngine->setRequestNdx($this->requestNdx);
		$srEngine->forceEmailTo = $this->recData['destEmail'];
		$srEngine->sendRequest();

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'user/envelope';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Znovu odelat požadavek '.$this->requestRecData['subject']];
		//$hdr ['info'][] = ['class' => 'info', 'value' => Json::encode($this->requestData['person']['login'])];

		return $hdr;
	}
}
