<?php

namespace e10pro\canteen;

use e10\TableForm, e10\Wizard, e10\utils;


/**
 * Class ResetPayersWizard
 * @package e10pro\canteen
 */
class ResetPayersWizard extends Wizard
{
	var $tableDevices;
	var $lanRecData = NULL;
	var $localServerRecData = NULL;
	var $localServerUrl = '';

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

	public function addParams ()
	{
		$dateBegin = $this->app->testGetParam('dateBegin');
		$this->recData['dateBegin'] = $dateBegin;
		$this->addInput('dateBegin', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 20);

		$dateEnd = $this->app->testGetParam('dateEnd');
		$this->recData['dateEnd'] = $dateEnd;
		$this->addInput('dateEnd', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 20);

		$canteen = $this->app->testGetParam('canteen');
		$this->recData['canteen'] = $canteen;
		$this->addInput('canteen', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 20);

		$this->addCheckBox('resetOnly', 'Pouze smazat stávající plátce');
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addParams();
		$this->closeForm ();
	}

	public function doIt ()
	{
		$me = new \e10pro\canteen\MonthEngine($this->app);

		$dateBeginStr = $this->recData['dateBegin'];
		$dateEndStr = $this->recData['dateEnd'];
		$canteenNdx = intval($this->recData['canteen']);

		$resetOnly = intval($this->recData['resetOnly']);

		if (!$dateBeginStr || !$dateEndStr || !$canteenNdx)
			return;

		$me->setPeriod(utils::createDateTime($dateBeginStr), utils::createDateTime($dateEndStr));
		$me->canteenNdx = $canteenNdx;
		$me->changeOrderToFee($resetOnly);

		// -- close wizard
		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-user-circle-o';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Nastavit plátce za externí strávníky'];

		return $hdr;
	}
}
