<?php
namespace e10pro\vendms\libs;


use \e10doc\core\libs\E10Utils, \E10\uiutils, \E10\utils;


/**
 * class ReportSalesByPersons
 */
class ReportSalesByPersons extends \e10doc\core\libs\reports\GlobalReport
{
	var $units;
	var $currencies;
	var $persons = [];

	var $periodBegin = NULL;
  var $periodEnd = NULL;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years'], 'defaultValue' => E10Utils::todayFiscalMonth($this->app)]);

		parent::init();

		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

    if (!$this->periodBegin)
    {
      $cpBegin = $this->reportParams ['fiscalPeriod']['values'][$this->reportParams ['fiscalPeriod']['value']];

      if (isset($cpBegin['dateBegin']))
        $this->periodBegin = Utils::createDateTime($cpBegin['dateBegin']);

      if (isset($cpBegin['dateEnd']))
        $this->periodEnd = Utils::createDateTime($cpBegin['dateEnd']);
    }
    else
    {
      $this->periodBegin = Utils::createDateTime($this->periodBegin);
      $this->periodEnd = Utils::createDateTime($this->periodEnd);
    }

		$this->setInfo('icon', 'e10doc-sale/customers');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		//$this->setInfo('note', '1', 'Všechny částky jsou bez DPH');
	}

	function createContent ()
	{
		$this->createContent_Amount ();
	}

	function createCoreData ()
	{
    $q = [];
    array_push($q, 'SELECT heads.person, heads.homeCurrency,');
    array_push($q, ' SUM(heads.sumBaseHc) AS price, COUNT(*) AS cnt,');
    array_push($q, ' persons.fullName AS personName, persons.[id] AS personId');
		array_push($q, ' FROM e10doc_core_heads AS [heads]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push($q, ' WHERE heads.docState = 4000 ');

		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		array_push($q, ' AND heads.docType IN %in', ['invno', 'cashreg']);
    array_push($q, ' GROUP BY heads.person, heads.homeCurrency');

		if ($this->subReportId === 'quantity')
    	array_push($q, ' ORDER BY cnt DESC, personName');
		elseif ($this->subReportId === 'amount')
    	array_push($q, ' ORDER BY price DESC, personName');
		else
		array_push($q, ' ORDER BY personName');

		$rows = $this->app->db()->query($q);

		$data = [];

		forEach ($rows as $r)
		{
			$newItem = $r->toArray();
			if ($r ['person'] != 0)
			{
				if (!in_array($r ['person'], $this->persons))
					$this->persons[] = $r ['person'];

				$newItem ['personId'] = ['text'=> $r ['personId'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r ['person']];

				// -- print button
				$btn = [
					'type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'system/actionPrint', 'text' => 'Přehled',
					'data-report' => 'e10pro.vendms.libs.ReportPersonsBuys',
					'data-table' => 'e10.persons.persons', 'data-pk' => $r['person'],
					'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
					'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
					'actionClass' => 'btn-xs', 'class' => 'pull-right'
				];
				$btn['subButtons'] = [];
				$btn['subButtons'][] = [
					'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconEmail', 'title' => 'Odeslat emailem', 'btnClass' => 'btn-default btn-xs',
					'data-table' => 'e10.persons.persons', 'data-pk' => $r['person'], 'data-class' => 'Shipard.Report.SendFormReportWizard',
					'data-addparams' => 'reportClass=' . 'e10pro.vendms.libs.ReportPersonsBuys' . '&documentTable=' . 'e10.persons.persons',
					'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
					'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
				];

				$newItem['buttons'] = [];
				$newItem['buttons'][] = $btn;
			}

			$data[] = $newItem;
		}

		return $data;
	}

	function createContent_Amount ()
	{
		$data = $this->createCoreData(TRUE);

		$h = [
      '#' => '#',
      'personId' => ' id',
      'personName' => 'Zákazník',
      'cnt' => '+Počet nákupů',
      'price' => '+Cena celkem',
      'homeCurrency' => 'Měna',
			'buttons' => 'Akce',
    ];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Prodeje podle osob');
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'abc', 'icon' => 'detailReportAlphabetically', 'title' => 'Abecedně'];
		$d[] = ['id' => 'amount', 'icon' => 'detailReportFinancially', 'title' => 'Finančně'];
		$d[] = ['id' => 'quantity', 'icon' => 'detailReportTop', 'title' => 'Dle množství'];

		return $d;
	}

	public function createToolbar ()
	{
		$buttons = parent::createToolbar();

		$buttons[] = [
			'text' => 'Rozeslat hromadně emailem', 'icon' => 'system/iconEmail',
			'type' => 'action', 'action' => 'addwizard', 'data-class' => 'e10pro.vendms.libs.ReportBuysOnePersonWizard',
			'data-param-period-begin' => $this->periodBegin->format('Y-m-d'),
			'data-param-period-end' => $this->periodEnd->format('Y-m-d'),
			'data-table' => 'e10.persons.persons', 'data-pk' => '0',
			'class' => 'btn-primary'
		];

		return $buttons;
	}
}

