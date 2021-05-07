<?php

namespace e10doc\sale;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \E10\utils, E10Doc\Core\e10utils;


/**
 * Class reportListInvoicesOut
 * @package E10Doc\Sale
 */
class reportListInvoicesOut extends \e10doc\core\libs\reports\GlobalReport
{
	public $taxPeriod = 0;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years', 'enableAll'], 'defaultValue' => e10utils::todayFiscalMonth($this->app)]);
		$this->addParam ('vatPeriod', 'vatPeriod', ['flags' => ['enableAll'], 'defaultValue' => 0]);

		parent::init();
	}

	function createContent ()
	{
		$q[] = 'SELECT heads.taxPeriod, heads.docNumber as docNumber, heads.dateTax as dateTax, heads.dateAccounting as dateAccounting, ';
		array_push ($q, 'persons.fullName as person, heads.toPay, heads.dateDue as dateDue, heads.title as title ');
		array_push ($q, 'FROM e10_persons_persons as persons LEFT JOIN e10doc_core_heads AS heads ON persons.ndx = heads.person ');
		array_push ($q, 'WHERE heads.docType = %s', 'invno', ' AND heads.docState = 4000');

		if ($this->reportParams ['fiscalPeriod']['value'])
			e10utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		if ($this->reportParams ['vatPeriod']['value'])
			e10utils::vatPeriodQuery ($q, $this->reportParams ['vatPeriod']['value']);

		array_push ($q, 'ORDER BY heads.dateAccounting, heads.docNumber, heads.ndx');

		$listInvoices = $this->app->db()->query ($q);

		$data = [];

		$totalToPay = 0.0;
		forEach ($listInvoices as $r)
		{
			$data [] = ['docNumber' => $r['docNumber'], 'dateTax' => utils::datef ($r['dateTax'], '%d'),
									'dateAccounting' => utils::datef ($r['dateAccounting'], '%d'), 'person' => $r['person'],
									'toPay' => $r['toPay'], 'dateDue' => \E10\df ($r['dateDue'], '%d'), 'title' => $r['title']];
			$totalToPay += $r['toPay'];
		}
		$total =  array ('docNumber' => 'Celkem', 'toPay' => $totalToPay);
		$total['_options']['class'] = 'subtotal';
		$data [] = $total;

		$this->setInfo('title', 'Kniha vydaných faktur');
		$this->setInfo('icon', 'e10-docs-invoices-out');

		if ($this->reportParams ['fiscalPeriod']['value'])
			$this->setInfo('param', 'Účetní období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		if ($this->reportParams ['vatPeriod']['value'])
			$this->setInfo('param', 'Období DPH', $this->reportParams ['vatPeriod']['activeTitle']);

		$h = ['#' => '#', 'docNumber' => 'Číslo dokladu', 'dateTax' => 'DUZP', 'dateAccounting' => 'Účetní datum', 'person' => 'Odběratel', 'toPay' => ' Částka', 'dateDue' => 'Splatnost', 'title' => 'Poznámka'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);
	}
}
