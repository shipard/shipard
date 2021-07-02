<?php

namespace e10doc\balance;

use \e10\TableViewDetail;


/**
 * Class ViewDetailPersonDefaulter
 * @package e10doc\balance
 */
class ViewDetailPersonDefaulter extends TableViewDetail
{
	public function createDetailContent()
	{
		$personNdx = $this->item['ndx'];
		$w = new \e10doc\finance\CashPayEngine($this->app());
		$w->setParams($personNdx);
		$data = $w->getReceivables();

		if (!count($data))
		{
			$info = ['icon' => 'iconSmile', 'text' => 'Všechno je uhrazeno', 'class' => 'h2'];
			$this->addContent(['pane' => 'e10-pane e10-pane-table', 'line' => $info, 'type' => 'line']);
			return;
		}

		$title = [['icon' => 'iconArrowCircleUp', 'text' => 'Neuhrazené doklady']];

		foreach ($w->personTotals['totals'] as $curr => $t)
		{ // totals by currencies
			$amount = $t['rest'];

			$title[] = [
					'text' => 'Uhradit v hotovosti', 'class' => 'pull-right', 'btnClass' => 'btn-primary btn-xs', 'XXXactionClass' => 'tst', 'icon' => 'system/iconMoney',
					'data-table' => 'e10.persons.persons', 'type' => 'action', 'action' => 'wizard', 'data-class' => 'e10doc.finance.CashPayWizard',
					'data-addparams' => 'person=' . $personNdx . '&amount=' . $amount, 'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => 'default'
			];
		}

		$h = [
				'#' => '#',
				'docNumber' => 'Doklad', 's1' => ' VS', 's2' => ' SS', 'date' => 'Splatnost',
				'curr' => 'Měna', 'request' => ' Předpis', 'payment' => ' Uhrazeno', 'rest' => ' Zbývá',
		];

		$this->addContent(['pane' => 'e10-pane e10-pane-table', 'title' => $title, 'type' => 'table', 'header' => $h, 'table' => $data]);
	}
}
