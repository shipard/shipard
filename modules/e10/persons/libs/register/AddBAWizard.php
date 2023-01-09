<?php

namespace e10\persons\libs\register;
use \Shipard\Form\Wizard;


/**
 * class AddBAWizard
 */
class AddBAWizard extends Wizard
{
	var $personId = '';
  var $personNdx = 0;

	function init()
	{
		$this->personId = $this->app()->testGetParam('personId');
    if (!isset($this->recData['personId']))
      $this->recData['personId'] = $this->personId;
    $this->personNdx = intval($this->app()->testGetParam('personNdx'));
    if (!isset($this->recData['personNdx']))
      $this->recData['personNdx'] = $this->personNdx;
    }

	public function doStep ()
	{
		if ($this->pageNumber == 1)
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
    $this->init();

    $reg = new \e10\persons\libs\register\PersonRegister($this->app());
		$reg->setPersonNdx($this->personNdx);


		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
      $this->addInput('personNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
      $this->addInput('personId', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
      foreach ($reg->missingBA as $mba)
      {
        $baId = 'BA_'.$mba['bankAccount'];
        $label = [
          ['text' => $mba['bankAccount'], 'class' => ''],
          //['text' => 'IČP: '.$mo['natId'], 'class' => 'label label-default'],
        ];
        $this->addCheckBox($baId, $label, '1', self::coRightCheckbox);
        $this->recData[$baId] = 1;
      }
  		$this->closeForm ();
	}

	public function doIt ()
	{
		$this->init();
    $this->personNdx = $this->recData['personNdx'];

    $addBAIds = [];
    foreach ($this->recData as $key => $value)
    {
      if (!str_starts_with($key, 'BA_'))
        continue;
      if ($value != 1)
        continue;

      $addBAIds[] = substr($key, 3);
    }

    $reg = new \e10\persons\libs\register\PersonRegister($this->app());
		$reg->setPersonNdx($this->personNdx);
    $reg->addBankAccounts($addBAIds);

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'docType/bank';

    $hdr ['info'][] = ['class' => 'title', 'value' => 'Načíst bankovní účty '/*.$this->requestRecData['subject']*/];
		//$hdr ['info'][] = ['class' => 'info', 'value' => Json::encode($this->requestData['person']['login'])];

		return $hdr;
	}
}
