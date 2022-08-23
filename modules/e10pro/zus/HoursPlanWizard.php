<?php

namespace e10pro\zus;

use E10\Wizard, E10\TableForm;


/**
 * Class HoursPlanWizard
 * @package e10pro\zus
 */
class HoursPlanWizard extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->generate();
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
		$this->recData['etkNdx'] = $this->focusedPK;

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('etkNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function generate ()
	{
		$eng = new \e10pro\zus\HoursPlanGenerator($this->app());
		$eng->setParams(['etkNdx' => $this->recData['etkNdx']]);
		$eng->run ();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Aktivovat třídní knihu'];

		$item = $this->app()->loadItem ($this->recData['etkNdx'], 'e10pro.zus.vyuky');
		$hdr ['info'][] = ['class' => 'info', 'value' => $item['nazev']];

		return $hdr;
	}
}
