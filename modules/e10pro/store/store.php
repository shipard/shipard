<?php

namespace E10Pro\Store;

use \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils, \E10\Widget, \E10\Application, \E10\TableViewPanel;
require_once __SHPD_MODULES_DIR__ . 'e10doc/balance/balance.php';


/**
 * Reporty
 *
 */


function currentFiscalPeriod ($app)
{
	$today = new \DateTime();
	$period = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE start <= %d AND end >= %d ORDER BY [globalOrder] DESC", $today, $today)->fetch ();
	if ($period)
		return $period ['ndx'];
	return 0;
}


function createFiscalPeriodCombo ($app, $fiscalPeriod, $label = 'Období')
{
	$periods = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] ORDER BY [globalOrder] DESC")->fetchAll ();

	$c = '';
	$c .= \E10\es ($label) . ': ';
	$c .= "<select name='fiscalMonth'>";
	forEach ($periods as $r)
	{
    $c .= "<option value='{$r['ndx']}'" . ($fiscalPeriod == $r['ndx'] ? " selected='selected'" : '') . '>' . $r['calendarYear'].' / ' . $r['calendarMonth'] .  '</option>';
	}
	$c .= '</select>';

	return $c;
}

function fiscalPeriod ($app, $ndx)
{
	$today = new \DateTime();
	$period = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE [ndx] = %i", $ndx)->fetch ();
	if ($period)
		return $period;
	return 0;
}



/**
 * reportSales
 *
 */

class reportSales extends \E10\GlobalReport
{
	public $fiscalMonth = 0;

	function init ()
	{
		parent::init();
		$this->fiscalMonth = intval ($this->app->testGetParam ('fiscalMonth'));
		if (!$this->fiscalMonth)
			$this->fiscalMonth = currentFiscalPeriod ($this->app);
	}

	function createReportContent ()
	{
		$cashBoxes = $this->app->cfgItem ('e10doc.cashBoxes', array());

		$data = array ();
		$usedCashBoxes = array();
		$usedDays = array();

		$q = "SELECT SUM(sumTotal) as sumTotal, SUM(sumBase) as sumBase, SUM(toPay) as sumToPay, cashBox, dateAccounting
			from e10doc_core_heads WHERE fiscalMonth = %i AND docState = 4000 AND docType in ('cashreg') GROUP BY dateIssue, cashBox ORDER BY dateIssue";
		$rows = $this->app->db()->query($q, $this->fiscalMonth);
		forEach ($rows as $r)
		{
			$dayKey = $r ['dateAccounting']->format('Y-m-d');
			$data [$dayKey][$r['cashBox']] = array ('date' => $r ['dateAccounting'], 'sumToPay' => $r['sumToPay'],
																							'sumTotal' => $r['sumTotal'], 'sumBase' => $r['sumBase']);

			if (!isset ($usedDays[$dayKey]))
				$usedDays[$dayKey] = $r ['dateAccounting'];

			if (!isset ($usedCashBoxes[$r['cashBox']]))
				$usedCashBoxes[$r['cashBox']] = 'CB'.$r['cashBox'];
		}

		$all = array ();
		forEach ($usedDays as $dayId => $dayDate)
		{
			$newRow = array ('date' => \E10\df ($dayDate, '%D'));
			$sumBase = 0.0;
			$sumTotal = 0.0;
			$sumToPay = 0.0;
			forEach ($usedCashBoxes as $cashBoxNdx => $cashBoxKey)
			{
				if (isset ($data [$dayId][$cashBoxNdx]))
				{
					$newRow [$cashBoxKey . 'sumBase'] = $data [$dayId][$cashBoxNdx]['sumBase'];
					$newRow [$cashBoxKey . 'sumTotal'] = $data [$dayId][$cashBoxNdx]['sumTotal'];
					$newRow [$cashBoxKey . 'sumToPay'] = $data [$dayId][$cashBoxNdx]['sumToPay'];
					$sumBase += $data [$dayId][$cashBoxNdx]['sumBase'];
					$sumToPay += $data [$dayId][$cashBoxNdx]['sumToPay'];
				}
			}
			$newRow ['sumBase'] = $sumBase;
			$newRow ['sumTotal'] = $sumTotal;
			$newRow ['sumToPay'] = $sumToPay;
			$all[] = $newRow;
		}

		$c = '';
		$c .= "<div class='e10-reportContent'>";
		$fiscalPeriod = fiscalPeriod ($this->app, $this->fiscalMonth);
		$c .= "<h1>Celkové tržby za období ".$fiscalPeriod['calendarYear'].' / '.$fiscalPeriod['calendarMonth'].'</h1>';

		$h = array ('#' => '#', 'date' => ' Datum', 'sumToPay' => '+CELKEM');
		forEach ($usedCashBoxes as $cashBoxNdx => $cashBoxKey)
		{
			$cb = utils::searchArray ($cashBoxes, 'ndx', $cashBoxNdx);
			if ($cb == NULL)
				$cb = array ('shortName' => 'Ostatní');
			$h [$cashBoxKey . 'sumToPay'] = '+' . $cb['shortName'];
		}

		$params = array ('tableClass' => 'e10-vd-mainTable');
		$c .= \E10\renderTableFromArray ($all, $h, $params);

		$c .= "</div>";

		//$c .= json_encode ($data);
		return $c;
	}

	public function createToolbarCode ()
	{
		$c = createFiscalPeriodCombo ($this->app, $this->fiscalMonth);
		$c .= parent::createToolbarCode ();
		return $c;
	}
} // class reportSales


/**
 * reportCash
 *
 */

class reportCash extends \E10\GlobalReport
{
	public $fiscalMonth = 0;

	function init ()
	{
		parent::init();
		$this->fiscalMonth = intval ($this->app->testGetParam ('fiscalMonth'));
		if (!$this->fiscalMonth)
			$this->fiscalMonth = currentFiscalPeriod ($this->app);
	}

	function createReportContent ()
	{
		$cashBoxes = $this->app->cfgItem ('e10doc.cashBoxes', array());

		$data = array ();
		$usedCashBoxes = array();
		$usedDays = array();

		$q = "SELECT SUM(totalCash) as toPay, cashBox, dateIssue
			from e10doc_core_heads WHERE fiscalMonth = %i AND docState = 4000 AND docType = 'cashreg' GROUP BY dateIssue, cashBox ORDER BY dateIssue";
		$rows = $this->app->db()->query($q, $this->fiscalMonth);
		forEach ($rows as $r)
		{
			$dayKey = $r ['dateIssue']->format('Y-m-d');
			$data [$dayKey][$r['cashBox']] = array ('date' => $r ['dateIssue'], 'toPay' => $r['toPay']);

			if (!isset ($usedDays[$dayKey]))
				$usedDays[$dayKey] = $r ['dateIssue'];

			if (!isset ($usedCashBoxes[$r['cashBox']]))
				$usedCashBoxes[$r['cashBox']] = 'CB'.$r['cashBox'];
		}

		$all = array ();
		forEach ($usedDays as $dayId => $dayDate)
		{
			$newRow = array ('date' => \E10\df ($dayDate, '%D'));
			$sumToPay = 0.0;
			forEach ($usedCashBoxes as $cashBoxNdx => $cashBoxKey)
			{
				if (isset ($data [$dayId][$cashBoxNdx]))
				{
					$newRow [$cashBoxKey . 'toPay'] = $data [$dayId][$cashBoxNdx]['toPay'];
					$sumToPay += $data [$dayId][$cashBoxNdx]['toPay'];
				}
			}
			$newRow ['sumToPay'] = $sumToPay;
			$all[] = $newRow;
		}

		$c = '';
		$c .= "<div class='e10-reportContent'>";

		$fiscalPeriod = fiscalPeriod ($this->app, $this->fiscalMonth);
		$c .= "<h1>Platby v hotovosti za období ".$fiscalPeriod['calendarYear'].' / '.$fiscalPeriod['calendarMonth'].'</h1>';

		$h = array ('#' => '#', 'date' => ' Datum', 'sumToPay' => '+CELKEM');
		forEach ($usedCashBoxes as $cashBoxNdx => $cashBoxKey)
		{
			$cb = utils::searchArray ($cashBoxes, 'ndx', $cashBoxNdx);
			$h [$cashBoxKey . 'toPay'] = '+' . $cb['shortName'];
		}

		$params = array ('tableClass' => 'e10-vd-mainTable');
		$c .= \E10\renderTableFromArray ($all, $h, $params);


		$c .= "</div>";

		//$c .= json_encode ($data);
		return $c;
	}

	public function createToolbarCode ()
	{
		$c = createFiscalPeriodCombo ($this->app, $this->fiscalMonth);
		$c .= parent::createToolbarCode ();
		return $c;
	}
} // class reportCash


/**
 * Seznam prodejek k fakturaci
 */

class InvoiceCashregDisposal extends \E10Doc\Balance\BalanceDisposalViewer
{
	public function init ()
	{
		$this->balance = 8100;
		$this->docCheckBoxes = 1;
		parent::init();
	}

	function decorateRow (&$item)
	{
		parent::decorateRow ($item);

		$buttons = array ();
		$buttons [] = ['text' => 'Vyfakturovat', 'docAction' => 'new', 'table' => 'e10doc.core.heads',
									 'addParams' => '__docType=invno'];
		$item['buttons'] = $buttons;

		$item['docActionData']['operation'] = '1080003';
		$item['docActionData']['title'] = 'Prodejky';

		// -- dbCounter: TODO: settings in sales options
		$dbCounters = $this->table->app()->cfgItem ('e10.docs.dbCounters.invno', FALSE);
		$item['docActionData']['dbCounter'] = key($dbCounters);
	}

	public function checkDocumentTile ($document, &$tile)
	{
		$tile['docActionData']['text'] = 'Fakturace prodejky '.$document['docNumber'];
		$tile['docActionData']['taxCode'] = '120'; // TODO: settings?
		$tile['docActionData']['weightNet'] = $document['weightNet'];
	}
} // InvoiceCashregDisposal


/**
 * reportBalanceSalesInvoice
 *
 */

class reportBalanceSalesInvoice extends \E10Doc\Balance\reportBalance
{
	function init ()
	{
		$this->balance = 8100;
		parent::init();
	}
}
