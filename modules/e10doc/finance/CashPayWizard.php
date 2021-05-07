<?php

namespace e10doc\finance;

require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';


use  e10\utils, e10doc\core\e10utils, e10\TableForm;


/**
 * Class CashPayWizard
 * @package e10doc\finance
 */
class CashPayWizard extends /*\E10\Wizard*/ \E10Doc\Core\CreateDocumentWizard
{
	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->stepResult['lastStep'] = 1;
			if ($this->saveDocument())
				$this->stepResult ['close'] = 1;
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
		$this->setFlag ('formStyle', 'e10-formStyleWizard');

		$this->openForm ();

		$personNdx = $this->app->testGetParam('person');
		$this->recData['personNdx'] = $personNdx;
		$amount = floatval($this->app->testGetParam('amount'));
		$this->recData['amount'] = $amount;

		$this->addInput('personNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->addInput('amount', 'Částka hotovosti', self::INPUT_STYLE_MONEY);

		$w = new \e10doc\finance\CashPayEngine($this->app());
		$w->setParams($personNdx);

		$data = $w->getReceivables();
		$h = [
				'#' => '#',
				'docNumber' => 'Doklad', 's1' => ' VS', 's2' => ' SS', 'date' => 'Splatnost',
				'curr' => 'Měna', 'request' => ' Předpis', 'payment' => ' Uhrazeno', 'rest' => ' Zbývá',
		];

		$this->addSeparator(TableForm::coH1);
		$this->addStatic('Přehled pohledávek k úhradě', TableForm::coH2);
		$this->addStatic(['type' => 'table', 'header' => $h, 'table' => $data]);

		$this->closeForm ();
	}

	protected function saveDocument ()
	{
		$w = new \e10doc\finance\CashPayEngine($this->app());
		$w->setParams($this->recData['personNdx']);
		$w->createCashDoc($this->recData['amount']);

		$this->stepResult ['refreshDetail'] = 1;

		return TRUE;
	}

	public function createHeader ()
	{
		$personRecData = $this->app()->loadItem($this->recData['personNdx'], 'e10.persons.persons');

		$hdr = ['icon' => 'icon-money'];

		$hdr ['info'][] = ['class' => 'info', 'value' => $personRecData['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Úhrada pohledávky'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Zadejte částku'];

		return $hdr;
	}
}
