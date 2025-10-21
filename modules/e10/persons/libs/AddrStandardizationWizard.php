<?php

namespace e10\persons\libs;
use \Shipard\Form\Wizard;
use \Shipard\Utils\World;


/**
 * class AddrStandardizationWizard
 */
class AddrStandardizationWizard extends Wizard
{
  var $addrPlaceId = '';
  var $addressNdx = 0;

	function init()
	{
		$this->addrPlaceId = $this->app()->testGetParam('addrPlaceId');
    if (!isset($this->recData['addrPlaceId']))
      $this->recData['addrPlaceId'] = $this->addrPlaceId;
    $this->addressNdx = intval($this->app()->testGetParam('addressNdx'));
    if (!isset($this->recData['addressNdx']))
      $this->recData['addressNdx'] = $this->addressNdx;
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

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
      $this->addInput('addressNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
      $this->addInput('addrPlaceId', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
    $this->closeForm ();
	}

  public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'user/envelope';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Standardizace adresy '];
		$hdr ['info'][] = ['class' => 'info', 'value' => [['text' => 'NAZDAR', 'icon' => 'system/iconGlobe']]];

		return $hdr;
	}

	public function doIt ()
	{
    $addrStandardizationEngine = new \e10\persons\libs\AddrStandardizationEngine($this->app());
    $addrStandardizationEngine->standardizeAddress($this->recData['addressNdx'], $this->recData['addrPlaceId']);

    $this->stepResult ['close'] = 1;
    $this->stepResult ['refreshDetail'] = 1;
  }
}
