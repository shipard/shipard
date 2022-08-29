<?php

namespace E10Doc\Buy;
use \e10\utils,  e10doc\core\libs\E10Utils;


/**
 * Class reportListInvoicesIn
 * @package E10Doc\Buy
 */
class reportListInvoicesIn extends \e10doc\core\libs\reports\GlobalReport
{
	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years', 'enableAll'], 'defaultValue' => E10Utils::todayFiscalMonth($this->app)]);
		$this->addParam ('vatPeriod', 'vatPeriod', ['flags' => ['enableAll'], 'defaultValue' => 0]);

		parent::init();
	}

	function createContent ()
	{
		$q[] = 'SELECT heads.taxPeriod, heads.docNumber as docNumber, heads.symbol1 as symbol1, heads.dateTax as dateTax,';
		array_push ($q, 'heads.dateAccounting as dateAccounting, persons.fullName as person, heads.toPay, heads.dateDue as dateDue,');
		array_push ($q, 'heads.title as title ');
		array_push ($q, 'FROM e10_persons_persons as persons LEFT JOIN e10doc_core_heads AS heads ON persons.ndx = heads.person ');
		array_push ($q, 'WHERE heads.docType = %s', 'invni', ' AND heads.docState = 4000');

		if ($this->reportParams ['fiscalPeriod']['value'])
			E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		if ($this->reportParams ['vatPeriod']['value'])
			E10Utils::vatPeriodQuery ($q, $this->reportParams ['vatPeriod']['value']);

		array_push ($q, 'ORDER BY heads.dateAccounting, heads.docNumber, heads.ndx');

		$listInvoices = $this->app->db()->query($q);

		$data = [];

		$totalToPay = 0.0;
		forEach ($listInvoices as $r)
		{
			$data [] = ['docNumber' => $r['docNumber'], 'symbol1' => $r['symbol1'], 'dateTax' => utils::datef ($r['dateTax'], '%d'),
									'dateAccounting' => utils::datef ($r['dateAccounting'], '%d'), 'person' => $r['person'],
									'toPay' => $r['toPay'], 'dateDue' => utils::datef ($r['dateDue'], '%d'), 'title' => $r['title']];
			$totalToPay += $r['toPay'];
		}
		$total =  array ('docNumber' => 'Celkem', 'toPay' => $totalToPay);
		$total['_options']['class'] = 'subtotal';
		$data [] = $total;

		$this->setInfo('title', 'Kniha přijatých faktur');
		$this->setInfo('icon', 'docTypeInvoicesIn');

		if ($this->reportParams ['fiscalPeriod']['value'])
			$this->setInfo('param', 'Účetní období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		if ($this->reportParams ['vatPeriod']['value'])
			$this->setInfo('param', 'Období DPH', $this->reportParams ['vatPeriod']['activeTitle']);

		$h = ['#' => '#', 'docNumber' => 'Číslo dokladu', 'symbol1' => 'Variabilní symbol', 'dateTax' => 'DUZP', 'dateAccounting' => 'Účetní datum', 'person' => 'Odběratel', 'toPay' => ' Částka', 'dateDue' => 'Splatnost', 'title' => 'Poznámka'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);
	}
} // class reportListInvoicesIn


