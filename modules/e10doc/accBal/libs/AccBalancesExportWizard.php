<?php

namespace e10doc\accBal\libs;

use \Shipard\Form\Wizard;


/**
 * class AccBalancesExportWizard
 */
class AccBalancesExportWizard extends Wizard
{
	var ?\e10doc\accBal\libs\AccBalanceCfg $balanceExport = NULL;
	var $balanceExportInfo = NULL;

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
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$this->addStatic('Můžete vybrat, která saldokonta chcete zahrnout do exportu:');
			$this->addBalancesCheckBoxes();
		$this->closeForm ('padd5');
	}

	public function doIt ()
	{
		$balances = [];
		foreach ($this->recData as $key => $value)
		{
			if ($value != 1)
			continue;
			if (strpos($key, 'balance-') === 0)
			{
				$ndx = intval(substr($key, 8));
				$balances[] = $ndx;
			}
		}

		if (count($balances))
		{
			$this->balanceExport = new \e10doc\accBal\libs\AccBalanceCfg($this->app());
			$this->balanceExportInfo = $this->balanceExport->exportBalances($balances);
		}

		$this->stepResult ['lastStep'] = 1;
	}

	public function renderFormDone ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->openForm ();

		$c = '';
		$c .= "<div style='padding: 1rem;'>";
		$c .= "<a href='{$this->balanceExportInfo['url']}' class='btn btn-primary' target='_blank' download='{$this->balanceExportInfo['baseName']}'>Stáhnout soubor</a>";
		$c .= '</div>';

		$this->appendCode ($c);

		$this->closeForm ();
	}

	protected function addBalancesCheckBoxes()
	{
		$balances = new \e10doc\accBal\libs\AccBalanceCfg($this->app());
		$balances->loadBalances();

		foreach ($balances->balances as $ndx => $balance)
		{
			$this->addCheckBox('balance-' . $ndx, $balance['fullName'], '1', self::coRightCheckbox);
			$this->recData['balance-' . $ndx] = 1;
		}
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'tables/e10doc.accBal.balances';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Export nastavení saldokont do souboru'];

		return $hdr;
	}
}
