<?php

namespace e10doc\ros;

use \e10\utils, \e10\TableFormShow, \e10\TableForm;


/**
 * Class RosRecordShow
 * @package e10doc\ros
 */
class RosRecordShow extends TableFormShow
{
	var $contentInfo = [];

	public function renderForm ()
	{
		$this->readOnly = TRUE;

		$this->loadData();

		$tabs ['tabs'][] = ['text' => 'Info', 'icon' => 'icon-info-circle'];
		$tabs ['tabs'][] = ['text' => 'Odesláno', 'icon' => 'icon-upload'];
		$tabs ['tabs'][] = ['text' => 'Přijato', 'icon' => 'icon-download'];

		$this->openForm (TableForm::ltNone);
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addContent($this->contentInfo);
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('dataSent', NULL, TableForm::coFullSizeY);
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('dataReceived', NULL, TableForm::coFullSizeY);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	function loadData()
	{
		$h = ['t' => 'text', 'v' => 'hodnota'];
		$t = [];
		$t[] = ['t' => 'ID zprávy', 'v' => $this->recData['msgId']];
		$t[] = ['t' => 'ID provozovny', 'v' => $this->recData['placeId1']];
		$t[] = ['t' => 'ID pokladny', 'v' => $this->recData['placeId2']];
		$t[] = ['t' => 'Částka', 'v' => $this->recData['amount']];
		$t[] = ['t' => 'Datum a čas tržby', 'v' => utils::datef ($this->recData['datePay'], '%x')];
		$t[] = ['t' => 'Datum a čas odeslání', 'v' => utils::datef ($this->recData['dateSent'], '%x')];
		$t[] = ['t' => 'Poř. číslo odeslání', 'v' => $this->recData['sendIndex']];
		$t[] = ['t' => 'FIK', 'v' => $this->recData['resultCode1']];
		$t[] = ['t' => 'BKP', 'v' => $this->recData['resultCode2']];
		$t[] = ['t' => 'Stav', 'v' => $this->recData['state']];

		$this->contentInfo[] = [
			'table' => $t, 'header' => $h,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		];
	}
}
