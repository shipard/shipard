<?php

namespace e10doc\balance\libs;
use \Shipard\Form\Wizard, \Shipard\Form\TableForm, \e10doc\core\libs\E10Utils;


/**
 * Class ExchDiffsWizard
 */
class ExchDiffsWizard extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt ();
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
		$this->recData['personNdx'] = intval($this->focusedPK);
		$this->recData['balance'] = intval($this->app()->testGetParam('balance'));
		$this->recData['currency'] = $this->app()->testGetParam('currency');
		$this->recData['fiscalYear'] = intval($this->app()->testGetParam('fiscalYear'));

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$eng = new \e10doc\balance\libs\ExchDiffsEngine ($this->app);
		$eng->setPerson($this->recData['personNdx'], $this->recData['balance'], $this->recData['currency'], $this->recData['fiscalYear']);
		$eng->loadData();

		$this->openForm ();
			$this->addInput ('personNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput ('balance', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput ('currency', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput ('fiscalYear', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

			$h = [
				'#' => '#',
				'docNumber' => 'Doklad', 's1' => ' VS', 's2' => ' SS', 'date' => 'Splatnost',
				'request' => ' Částka CM', 'curr' => 'Měna',
				'requestHc' =>  ' Předpis DM', 'paymentHc' => ' Uhrazeno DM', 'restHc' => ' Zbývá DM',
				'debsAccountId' => 'Účet'
			];

			$this->layoutOpen(self::ltHorizontal);
				$this->addStatic (['type' => 'table', 'header' => $h, 'table' => $eng->data, '_title' => 'TEST', '__main' => TRUE, 'params' => ['disableZeros' => 1]]);
			$this->layoutClose('padd5');
		$this->closeForm ();
	}

	public function doIt ()
	{
		$eng = new \e10doc\balance\libs\ExchDiffsEngine ($this->app);
		$eng->setPerson($this->recData['personNdx'], $this->recData['balance'], $this->recData['currency'], $this->recData['fiscalYear']);

		$eng->run();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['editDocument'] = 1;
		$this->stepResult ['params'] = ['table' => 'e10doc.core.heads', 'pk' => $eng->newDocNdx];
	}

	public function createHeader ()
	{
		$balances = $this->app()->cfgItem ('e10.balance');
    $thisBalanceDef = $balances[$this->recData['balance']];

		$personRecData = $this->app()->loadItem($this->recData['personNdx'], 'e10.persons.persons');

		$hdr = [];
		$hdr ['icon'] = $thisBalanceDef['icon'];
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Zaúčtování kurových rozdílů - '.$thisBalanceDef['name']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $personRecData['fullName']];

		return $hdr;
	}
}
