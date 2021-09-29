<?php

namespace E10Doc\Finance;
use \E10\utils, e10doc\core\libs\E10Utils;

/**
 * reportCashBook
 *
 */

class reportCashBook extends \e10doc\core\libs\reports\GlobalReport
{
	function init ()
	{
		$periodFlags = array('quarters', 'halfs', 'years');
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', array('flags' => $periodFlags));
		$this->addParam ('cashBox');
		parent::init();
	}

	function createContent ()
	{
		$fmNdx = $this->reportParams ['fiscalPeriod']['value'];
		$fmBeginDate = $this->reportParams ['fiscalPeriod']['values'][$fmNdx]['dateBegin'];
		$fmFiscalYear = $this->reportParams ['fiscalPeriod']['values'][$fmNdx]['fiscalYear'];

		$q [] = "SELECT heads.[ndx] as ndx, [docNumber], [title], heads.[docType] as [docType], [toPay], [person], [currency], [homeCurrency], [dateAccounting], [totalCash],
							heads.initState as initState, persons.fullName as personFullName
							FROM e10_persons_persons AS persons RIGHT JOIN [e10doc_core_heads] as heads ON (heads.person = persons.ndx) WHERE 1";

		E10Utils::fiscalPeriodQuery ($q, $fmNdx);

		array_push ($q, " AND heads.cashBox = %i", $this->reportParams ['cashBox']['value']);
		array_push ($q, " AND heads.docType IN ('invni', 'invno', 'cash', 'cashreg', 'purchase')");
		array_push ($q, " AND heads.totalCash != 0 AND heads.docState = 4000");
		array_push ($q, " ORDER BY heads.[dateAccounting], heads.[docNumber]");

		$data = array ();
		$rows = $this->app->db()->query($q);

		$bilance = E10Utils::getCashBoxInitState ($this->app, $this->reportParams ['cashBox']['value'], $fmBeginDate, $fmFiscalYear);

		$data [] = array (
			'docNumber' => 'Počáteční zůstatek', 'bilance' => $bilance, '_options' => array ('class' => 'subtotal', 'colSpan' => array ('docNumber' => 7))
		);

		forEach ($rows as $r)
		{
			$docType = $this->app()->cfgItem ('e10.docs.types.' . $r['docType']);
			$bilance += $r['totalCash'];
			$newItem = array (
				'docNumber' => array ('text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['ndx']),
				'doctype' => $docType['shortcut'], 'title' => $r['title'],
				'dateAccounting' => $r['dateAccounting'], 'person' => $r['personFullName'],
				'bilance' => $bilance,
			);

			if ($r['totalCash'] < 0)
				$newItem ['cashOut'] = $r['totalCash'] * -1;
			else
				$newItem ['cashIn'] = $r['totalCash'];
			$data [] = $newItem;
		}
		$data [] = array (
			'docNumber' => 'Konečný zůstatek', 'bilance' => $bilance, '_options' => array ('class' => 'subtotal', 'colSpan' => array ('docNumber' => 7))
		);

		$h = array ('#' => '#', 'docNumber' => 'Doklad', 'doctype' => 'DD',
								'dateAccounting' => 'Účetní datum', 'person' => 'Partner', 'title' => 'Poznámka',
								'cashIn' => '+Přijato', 'cashOut' => '+Vyplaceno', 'bilance' => ' Zůstatek');

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE));

		$this->setInfo('title', 'Pokladní kniha');
		$this->setInfo('icon', 'report/cashBook');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('param', 'Pokladna', $this->reportParams ['cashBox']['activeTitle']);
		$cashBoxes = $this->app->cfgItem ('e10doc.cashBoxes', array());
		$cashBoxCurrency = $cashBoxes[$this->reportParams ['cashBox']['value']]['curr'];
		$currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->setInfo('note', '1', 'Všechny částky jsou v '.$currencies[$cashBoxCurrency]['shortcut']);
		$this->setInfo('saveFileName', 'Pokladní kniha '.str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']).' - '.$this->reportParams ['cashBox']['activeTitle']);
	}
} // class reportCashBook



