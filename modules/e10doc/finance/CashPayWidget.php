<?php

namespace e10doc\finance;

use  e10\utils;


/**
 * Class CashPayWidget
 * @package e10doc\finance
 */
class CashPayWidget extends \Shipard\UI\Core\WidgetPane
{
	var $personsTable;
	var $personNdx = 0;
	var $personRecData;

	var $amount;
	var $currency;

	var $code;

	protected function loadData()
	{
		$this->personsTable = $this->app->table ('e10.persons.persons');
		$this->personNdx = intval($this->app->requestPath(3));
		$this->personRecData = $this->personsTable->loadItem ($this->personNdx);

		$w = new \e10doc\finance\CashPayEngine($this->app);
		$w->setParams($this->personNdx);
		$data = $w->getReceivables();

		foreach ($w->personTotals['totals'] as $curr => $t)
		{ // totals by currencies
			$this->amount = $t['rest'];
			$this->currency = $curr;

			break;
		}
	}

	protected function composeCode ()
	{
		$c = '';
		$c .= $this->composeCodePay();
		$c .= $this->composeCodeDone();
		$c .= $this->composeCodeInitScript();

		return $c;
	}

	protected function composeCodePay ()
	{
		$c = '';

		$headerTitle = [['text' => 'Uhradit dluh: '.utils::nf($this->amount, 2), 'suffix' => $this->currency, 'class' => 'h1 block']];
		$headerTitle[] = ['text' => $this->personRecData['fullName'], 'icon' => $this->personsTable->tableIcon ($this->personRecData), 'class' => 'h2'];

		$c .= "<div class='e10-wcb-pay'>";

		$c .= "<div class='pay-left'>";
			$c .= "<div class='header multiLine'>";
				$c .= "<div>".$this->app()->ui()->composeTextLine($headerTitle)."</div>";
			$c .= '</div>';

			$c .= "<div class='pay-methods'>";
				$c .= "<span class='e10-cashpay-action active' data-action='change-payment-method' data-pay-method='1'>Hotově</span>";
				$c .= "<span class='e10-cashpay-action' data-action='change-payment-method' data-pay-method='2'>Kartou</span>";
			$c .= '</div>';
		$c .= '</div>';

		$c .= "<div class='pay-right'>";
			$c .= "<div class='e10-cashpay-action pay-display' data-action='change-amount'>";
				$c .= "<span class='money-to-pay pull-right' id='e10-widget-cashpay-display' data-amount='{$this->amount}'>".utils::nf($this->amount, 2)."</span>";
			$c .= '</div>';

			$c .= "<div class='done-buttons'>";
				$c .= "<button class='btn btn-primary e10-cashpay-action' data-action='cashpay-done'>ZAPLACENO</button>";
			$c .= '</div>';
		$c .= '</div>';

		$c .= "<div class='back-buttons'>";
			$c .= "<button class='e10-trigger-action btn btn-info' id='e10-back-button' data-action='modal-close'><i class='fa fa-arrow-left'></i> Zpět</button>";
		$c .= '</div>';

		$c .= '</div>';

		return $c;
	}

	protected function composeCodeDone ()
	{
		$c = '';

		$c .= "<div class='e10-wcb-done' style='display: none;'>";

		$c .= "<div class='header'>";
		$c .= "Účtenka se ukládá";
		$c .= '</div>';

		$c .= "<div class='done-status'>";
		$c .= '</div>';


		$c .= "<div class='done-buttons' style='display: none;'>";
		$c .= "<button class='btn btn-primary e10-terminal-action' data-action='terminal-retry'>Zkusit to znovu</button>";
		$c .= "<button class='btn btn-primary e10-terminal-action' data-action='terminal-queue'>Vyřešit to později</button>";
		$c .= '</div>';

		$c .= '</div>';

		return $c;
	}


	protected function composeCodeInitScript ()
	{
		$c = "<script>e10.widgets.cashPay.init ('{$this->widgetId}');</script>";

		return $c;
	}

	public function createContent ()
	{
		$this->loadData();

		$this->widgetSystemParams['data-cashbox'] = ($this->app->workplace && $this->app->workplace['cashBox']) ? $this->app->workplace['cashBox'] : 1;
		$this->widgetSystemParams['data-amount'] = strval($this->amount);
		$this->widgetSystemParams['data-currency'] = $this->currency;
		$this->widgetSystemParams['data-person'] = $this->personNdx;

		$this->code = $this->composeCode();
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->code]);
	}

	public function title()
	{
		return FALSE;
	}

	public function setDefinition ($d)
	{
		$this->definition = ['class' => 'e10-widget-cashpay', 'type' => 'terminal'];
	}

	public function fullScreen()
	{
		return 1;
	}

	public function pageType()
	{
		return 'terminal';
	}
}
