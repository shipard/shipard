<?php

namespace E10Doc\Balance;
use e10doc\core\libs\E10Utils, \E10\utils;


function balanceRecalc ($app, $options = NULL)
{
	$br = new Balance ($app);
	$br->createBalanceJournal (2012);

	$objectData ['message'] = 'saldo je přepočítáno.';
	$objectData ['finalAction'] = 'reloadPanel';

	$r = new \E10\Response ($app);
	$r->add ("objectType", "panelAction");
	$r->add ("object", $objectData);

	return $r;
}


class Balance extends \E10\Utility
{
	protected $documentHead;

	protected function balancePartAccount ($b, $bp)
	{
		$accountMask = '';
		if (isset($bp['debsAccountMask']))
			$accountMask = $bp['debsAccountMask'];
		else
		if (isset($b['debsAccountMask']))
			$accountMask = $b['debsAccountMask'];

		if ($accountMask === '' || $accountMask[0] === '#')
			return $accountMask;

		if ($this->app->model()->table ('e10doc.debs.accounts') === FALSE)
			return $accountMask;

		$row = $this->db()->query ("SELECT * FROM [e10doc_debs_accounts] WHERE [accGroup] = 0 AND [id] LIKE %s ORDER by id, ndx", $accountMask.'%')->fetch();
		if ($row)
			return $row['id'];

		return $accountMask.str_repeat('9', 6 - strlen ($accountMask));
	}

	function balancePartCmdHead ($b, $bp)
	{
		$defaultAccountId = $this->balancePartAccount ($b, $bp);

		$pairIdCmdCore = preg_replace_callback ("/{([^{}]+)}/",
																						function ($matches){return "',".$matches[1].",'";},
																						$bp['itemId']);
		$pairIdCmd = "CONCAT('" . $pairIdCmdCore . "'), ";


		$amountColumn = 'toPay';
		if (isset ($bp['amountColumn']))
		{
			if ($bp['amountColumn'] === 'taxBase') // prevent sql injection
				$amountColumn = 'sumBase';
		}


		$q [] = "INSERT INTO [e10doc_balance_journal] " .
						"([pairId], [type], [side], [currency], [homeCurrency], [amount], [amountHc], [request], [requestHc], [payment], [paymentHc], [symbol1], [symbol2], [bankAccount], [date], [fiscalYear], [docLine], [docHead], [person], [debsAccountId]) ";

		array_push ($q,
						"SELECT ",
						$pairIdCmd,
						"%i, ", $b['id'],
						"0, ",
						"currency, homeCurrency, ",
						"$amountColumn, {$amountColumn}Hc, ", // amount
						"$amountColumn, {$amountColumn}Hc, ", // request
						"0, 0, ", 					// payment
						"[symbol1], [symbol2], [bankAccount], ",
						"dateDue, ",
						"fiscalYear, ",
						"0, heads.ndx, personBalance, ",
						'COALESCE(dockinds.debsAccountId,%s)', $defaultAccountId,
						' from [e10doc_core_heads] as heads LEFT JOIN e10doc_base_dockinds as dockinds ON (heads.docKind = dockinds.ndx) WHERE 1');

		if (isset ($this->documentHead))
			array_push ($q, ' AND heads.ndx = %i', $this->documentHead['ndx']);

		if (isset ($bp['cashBoxDir']))
			array_push ($q, "AND heads.cashBoxDir = %i ", intval($bp['cashBoxDir']));

		array_push ($q, " AND heads.docType = %s", $bp['docType']);
		array_push ($q, " AND heads.docState = 4000");

		if (isset ($bp['paymentMethod']))
			array_push ($q, "AND paymentMethod = %i ", intval($bp['paymentMethod']));

		array_push ($q, "AND paymentMethod != 1 "); // TODO: nastavit to na saldu

		return $q;
	}

	function balancePartCmdRow ($b, $bp)
	{
		$docType = $this->app()->cfgItem ('e10.docs.types.'.$bp['docType'], FALSE);
		if ($docType === FALSE)
			return FALSE;

		$defaultAccountId = '';

		$pairIdCmdCore = preg_replace_callback ("/{([^{}]+)}/",
																						function ($matches){return "',".$matches[1].",'";},
																						$bp['itemId']);
		$pairIdCmd = "CONCAT('" . $pairIdCmdCore . "'), ";

		$natural = (isset($b['type']) && $b['type'] === 'q');

		$q [] = "INSERT INTO [e10doc_balance_journal] " .
						'([pairId], [type], [side], [currency], [homeCurrency], ' .
						'[amount], [amountHc], [request], [requestHc], [payment], [paymentHc], ';

		if ($natural)
			array_push ($q, '[item], [unit], [amountQ], [requestQ], [paymentQ], ');

		array_push ($q, '[symbol1], [symbol2], [symbol3], [bankAccount], [date], [fiscalYear], [docLine], [docHead], [person], [debsAccountId]) ');

		$side = (isset($bp['side'])) ? intval ($bp['side']) : 1;
		$sideDbValue = $side;
		$moneySide = (isset($bp['moneySide'])) ? $bp['moneySide'] : '';
		$reverseSign = (isset($bp['reverseSign'])) ? intval ($bp['reverseSign']) : 0;

		$amountColumn = 'taxBase';
		if (isset ($bp['amountColumn']))
		{
			if ($bp['amountColumn'] === 'priceAll') // prevent sql injection
				$amountColumn = 'priceAll';
		}

		$secAmountColumn = $amountColumn;
		$secCurrencyColumn = 'currency';
		if ($amountColumn === 'taxBase' && $bp['docType'] === 'bank')
		{
			$amountValueCode = "[rows].bankRequestAmount, ";
			$secAmountColumn = 'bankRequestAmount';
			$secCurrencyColumn = 'bankRequestCurrency';
		}
		else
			$amountValueCode = "[rows].$amountColumn, ";

		if (isset ($bp['amountColumn']))
			$amountValueCode .= "[rows].{$amountColumn}Hc, ";
		else
			$amountValueCode .= "([rows].{$amountColumn}Hc + [rows].taxBaseHcCorr), ";

		$naturalCode = '[item], [unit], [rows].[quantity], ';

		if ($side === 0)
		{ // request
			$defaultAccountId = $this->balancePartAccount ($b, $bp);

			if ($reverseSign)
				$requestValueCode = "[$secAmountColumn]*-1, ([{$amountColumn}Hc]+[{$amountColumn}HcCorr])*-1, ";
			else
				$requestValueCode = "$secAmountColumn, ([{$amountColumn}Hc]+[{$amountColumn}HcCorr]), ";
			$paymentValueCode = '0, 0, ';
			$naturalCode .= '[rows].[quantity], 0, ';
		}
		else
		if ($side === 1)
		{ // payment
			$requestValueCode = '0, 0, ';
			if ($reverseSign)
				$paymentValueCode = "[$secAmountColumn]*-1, ([{$amountColumn}Hc]+[{$amountColumn}HcCorr])*-1, ";
			else
				$paymentValueCode = "$secAmountColumn, ([{$amountColumn}Hc]+[{$amountColumn}HcCorr]), ";
			$naturalCode .= '0, [rows].[quantity], ';
		}
		else
		if ($side === 2)
		{ // request in home currency - exchange rate adjustment
			$paymentValueCode = '0, 0, ';
			if ($reverseSign)
				$requestValueCode = "0, [{$amountColumn}Hc]*-1, ";
			else
				$requestValueCode = "0, {$amountColumn}Hc, ";
			$amountValueCode = "0, [rows].{$amountColumn}Hc, ";
			$sideDbValue = 0;
			$naturalCode .= '[rows].[quantity], 0, ';
		}
		else
		if ($side === 3)
		{ // payment in home currency - exchange rate adjustment
			$requestValueCode = '0, 0, ';
			if ($reverseSign)
				$paymentValueCode = "0, [{$amountColumn}Hc]*-1, ";
			else
				$paymentValueCode = "0, {$amountColumn}Hc, ";
			$amountValueCode = "0, [rows].{$amountColumn}Hc, ";
			$sideDbValue = 1;
			$naturalCode .= '0, [rows].[quantity], ';
		}

		$personSide = (isset($bp['personSide'])) ? intval ($bp['personSide']) : 1;
		if ($personSide === 0)
		{
			if ($b['id'] == '1000')
				$personValueCode = 'heads.personBalance, ';
			else
				$personValueCode = 'heads.person, ';
		}
		else
			$personValueCode = '[rows].person, ';

		$symbolsSide = (isset($bp['symbolsSide'])) ? intval ($bp['symbolsSide']) : 1;
		if ($symbolsSide === 0)
			$symbolsCode = "heads.[symbol1], heads.[symbol2], '', heads.[bankAccount], ";
		else
			$symbolsCode = '[rows].[symbol1], [rows].[symbol2], [rows].[symbol3], [rows].[bankAccount], ';

		array_push ($q,
						"SELECT ",
						$pairIdCmd,
						"%i, ", $b['id'],
						"%i, ", $sideDbValue,
						"[rows].$secCurrencyColumn, heads.homeCurrency, ",
						$amountValueCode, // amount
						$requestValueCode,
						$paymentValueCode);

		if ($natural)
			array_push ($q, $naturalCode);

		array_push ($q,
						$symbolsCode,
						"[rows].dateDue, ",
						"[heads].fiscalYear, ",
						"[rows].ndx, [heads].ndx, ",
						$personValueCode);

		//if (isset ($bp['checkItemBalance']))
		if ($side == 0)
			array_push ($q, ' COALESCE(items.debsAccountId,%s)', $defaultAccountId);
		else
			array_push ($q, '%s', '');

		array_push ($q, ' FROM [e10doc_core_rows] AS [rows] LEFT JOIN [e10doc_core_heads] AS [heads] ON [rows].[document] = [heads].ndx');

		//if (isset ($bp['checkItemBalance']))
		if ($side == 0)
			array_push ($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [rows].item = items.ndx');

		array_push ($q, ' WHERE 1 ');

		if (isset ($this->documentHead))
			array_push ($q, "AND [rows].[document] = %i ", $this->documentHead['ndx']);

		if (isset ($bp['checkItemBalance']))
			array_push ($q, "AND [rows].itemBalance = %i ", $b['id']);

		array_push ($q, "AND heads.docType = %s ", $bp['docType']);

		if (isset ($bp['operation']))
			array_push ($q, "AND [rows].operation = %i ", intval($bp['operation']));
		else
		if (isset ($bp['operations']))
			array_push ($q, "AND [rows].operation IN %in ", $bp['operations']);

		if (isset ($bp['paymentMethod']))
			array_push ($q, "AND heads.paymentMethod = %i ", intval($bp['paymentMethod']));

		if ($moneySide !== '')
		{
			if ($bp['docType'] === 'bank' || $bp['docType'] === 'cmnbkp')
			{
				if ($moneySide === 'dr') array_push ($q, "AND [rows].debit != 0 ");
				elseif ($moneySide === 'cr') array_push ($q, "AND [rows].credit != 0 ");
			}
			else
			if ($bp['docType'] === 'cash')
			{
				if ($moneySide === 'dr') array_push ($q, "AND heads.cashBoxDir = 2 "); // in
				elseif ($moneySide === 'cr') array_push ($q, "AND heads.cashBoxDir = 1 "); // out
			}
			else
			{
				if ($docType['docDir'] === 2) // invno
				{
					if ($moneySide === 'dr') array_push ($q, "AND [rows].$amountColumn >= 0 ");
					elseif ($moneySide === 'cr') array_push ($q, "AND [rows].$amountColumn < 0 ");
				}
				else
				if ($docType['docDir'] === 1) // invni
				{
					if ($moneySide === 'cr') array_push ($q, "AND [rows].$amountColumn >= 0 ");
					elseif ($moneySide === 'dr') array_push ($q, "AND [rows].$amountColumn < 0 ");
				}
			}
		}

		array_push ($q, "AND heads.docState = 4000 ");

		//if ($side === 0) // TODO: nastavení na saldu, pokud to vůbec bude potřeba
		//	array_push ($q, "AND heads.paymentMethod != 1 ");

		return $q;
	}

	function balacencePartCmd ($b, $bp)
	{
		switch ($bp ['src'])
		{
			case 'head':	return $this->balancePartCmdHead ($b, $bp);
			case 'row':		return $this->balancePartCmdRow ($b, $bp);
		}

		return FALSE;
	}

	function createBalanceJournal ($fiscalYear = 0)
	{
		if ($fiscalYear !== 0)
		{
			$q = "DELETE FROM [e10doc_balance_journal]";
			$this->app->db()->query ($q);
		}

		$balances = $this->app->cfgItem ('e10.balance');
		forEach ($balances as $b)
		{
			if (isset ($this->documentHead) && isset ($b['docTypes']) && !in_array($this->documentHead['docType'], $b['docTypes']))
				continue;

			forEach ($b['content'] as $bp)
			{
				if (isset ($this->documentHead) && isset ($bp['docType']) && $this->documentHead['docType']!== $bp['docType'])
					continue;

				$cmd = $this->balacencePartCmd($b, $bp);
				if ($cmd === FALSE)
					continue;

				$this->app->db()->query ($cmd);
			}
		}
	}

	public function run ()
	{
	}

	public function clearDocumentRows ()
	{
		$q = "DELETE FROM [e10doc_balance_journal] WHERE docHead = %i";
		$this->app->db()->query ($q, $this->documentHead['ndx']);
	}

	public function setDocument ($documentHead)
	{
		$this->documentHead = $documentHead;
	}
} // Balance


/**
 * reportBalance
 *
 */

class reportBalance extends \e10doc\core\libs\reports\GlobalReport
{
	const bmNormal = 0, bmExchangeDifference = 1, bmHomeCurrency = 2, bmQuantity = 3;

	public $fiscalYear = 0;
	protected $dataFiscalYear = array();
	protected $fiscalMonth = 0;
	protected $dataFiscalMonth = array();
	protected $balance = 0;
	protected $docTypes;
	protected $paymentMethods;
	public $balances;
	var $currencies;

	var $mode = self::bmNormal;

	public $balanceKinds = array();
	var $balanceKind = '0';
	var $displayItem = '0';
	public $dataSubTotals = [];
	var $pidColumnName = 'person';
	var $sortColumnName = 'fullName';

	protected $paymentInfo = [];

	var $personNdx = 0;
	var $disableSums = FALSE;

	function init ()
	{
		if ($this->subReportId != 'exchDiffs' && ($this->balance == 1000 || $this->balance == 2000))
			$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['years'], 'defaultValue' => 'Y'.E10Utils::todayFiscalYear($this->app)]);
		else
			$this->addParam ('fiscalYear');
		$this->addParamBalanceKind ();

		if ($this->subReportId == '' || $this->subReportId == 'items')
		{
			if ($this->balance == 1000)
				$this->addParam('switch', 'displayItem', array('title' => 'Zobrazit', 'switch' => ['0' => 'Vše', '10' => 'Splatné', '20' => 'Nesplatné']));
			if ($this->balance == 2000)
				$this->addParam('switch', 'displayItem', array('title' => 'Zobrazit', 'switch' => ['0' => 'Vše', '10' => 'Splatné', '11' => 'Splatné bez příkazu', '20' => 'Nesplatné', '30' => 'Jen s příkazy']));
		}

		$this->addParamPerson ();
		$this->addParam ('switch', 'unpairedPayments', ['title' => 'Nespárované úhrady', 'place' => 'panel', 'switch' => array ('show' => 'Zobrazovat', 'hide' => 'Nezobrazovat')]);
		$this->addParam ('switch', 'balanceOverDue', ['title' => 'Po splatnosti', 'place' => 'panel', 'switch' => array ('highlight' => 'Zvýrazňovat', 'normal' => 'Nezvýrazňovat')]);
		$this->addParam ('switch', 'orderBy', ['title' => 'Pořadí', 'place' => 'panel', 'switch' => array ('byName' => 'Podle jména', 'byDateDue' => 'Podle splatnosti')]);

		parent::init();

		$this->docTypes = $this->app->cfgItem ('e10.docs.types');
		$this->paymentMethods = $this->app->cfgItem ('e10.docs.paymentMethods');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->balances = $this->app->cfgItem ('e10.balance');
		$this->fiscalYear = $this->getFiscalYear($this->reportParams);
		if (isset($this->reportParams ['balanceKind']['value']))
			$this->balanceKind = $this->reportParams ['balanceKind']['value'];
		if (isset($this->reportParams ['displayItem']['value']))
			$this->displayItem = $this->reportParams ['displayItem']['value'];

		$tableFiscalYear = new \E10Doc\Base\TableFiscalYears($this->app);
		$itemFiscalYear = array ('ndx' => $this->fiscalYear);
		$this->dataFiscalYear = $tableFiscalYear->loadItem ($itemFiscalYear);
	}

	function getFiscalYear ($params)
	{
		if (isset ($params ['fiscalYear']['value']))
			return intval($params ['fiscalYear']['value']);
		else
		{
			if ($params ['fiscalPeriod']['value'][0] === 'Y')
				return intval(substr($params ['fiscalPeriod']['value'], 1));
			else
			{
				$tableFiscalMonth = new \E10Doc\Base\TableFiscalMonths($this->app);
				$itemFiscalMonth = array ('ndx' => $params ['fiscalPeriod']['value']);
				$this->dataFiscalMonth = $tableFiscalMonth->loadItem ($itemFiscalMonth);
				$this->fiscalMonth = $params ['fiscalPeriod']['value'];
				return $this->dataFiscalMonth['fiscalYear'];
			}
		}
		return 0;
	}

	function addParamPerson ()
	{
		$personsSwitch = array ('0' => 'Vše');
		$q = "SELECT b.[person] as person, p.[fullName] as fullName FROM e10doc_balance_journal as b LEFT JOIN e10_persons_persons as p ON b.person = p.ndx WHERE type=%i GROUP BY person, fullName ORDER BY fullName";
		$persons = $this->app->db()->query($q, $this->balance);
		forEach ($persons as $p)
		{
			if ($p['person'] != 0)
			{
				if ($p['fullName'] != '')
				{
					if (mb_strlen ($p['fullName']) > 28)
						$personsSwitch [$p['person']] = mb_substr($p['fullName'], 0, 25).'...';
					else
						$personsSwitch [$p['person']] = $p['fullName'];
				}
				else
					$personsSwitch [$p['person']] = '#'.$p['person'];
			}
		}
		$this->addParam  ('switch', 'person', ['title' => 'Osoba', 'place' => 'panel', 'switch' => $personsSwitch]);
	}

	protected function addParamBalanceKind ()
	{
		if ($this->app->model()->table ('e10doc.debs.accounts') === FALSE)
			return;

		if ($this->subReportId != 'exchDiffs' && ($this->balance == 1000 || $this->balance == 2000))
			$this->params->detectParamValue ('fiscalPeriod');
		else
			$this->params->detectParamValue ('fiscalYear');
		$params = $this->params->getParams();

		$q = 'select debsAccountId from e10doc_balance_journal where fiscalYear = %i AND [type] = %i AND side = 0 GROUP BY debsAccountId';

		$this->balanceKinds ['0'] = 'Vše';

		$ids = array ();
		$rows = $this->app->db()->query($q, $this->getFiscalYear($params), $this->balance);
		foreach ($rows as $r)
		{
			$this->balanceKinds [$r['debsAccountId']] = $r['debsAccountId'];
			$ids[] = $r['debsAccountId'];
		}

		if (count($ids) > 0)
		{
			$qac = "SELECT id, shortName FROM e10doc_debs_accounts WHERE docStateMain < 3 AND id IN %in";
			$accounts = $this->app->db()->query($qac, $ids);
			forEach ($accounts as $r)
				$this->balanceKinds [$r['id']] = $r['shortName'];
		}
		if (isset($this->balanceKinds['#proforma']))
			$this->balanceKinds['#proforma'] = "Zálohové faktury";

		$this->addParam('switch', 'balanceKind', array ('title' => 'Druh salda', 'switch' => $this->balanceKinds));
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'items': $this->createContent_Default(); break;
			case 'analysis': $this->createContent_Analysis(); break;
			case 'exchDiffs': $this->createContent_ExchangeDifferencies(); break;
		}
	}

	function createContent_Default ()
	{
		$this->loadPaymentInfo();

		$thisBalanceDef = $this->balances[$this->balance];
		if (isset ($thisBalanceDef['type']))
		{
			if ($thisBalanceDef['type'] === 'hc')
				$this->createContent_DefaultHc();
			else
			if ($thisBalanceDef['type'] === 'q')
				$this->createContent_DefaultQ();
			return;
		}

		$data = $this->prepareData();

		if (isset ($thisBalanceDef['tableHeader']))
			$h = $thisBalanceDef['tableHeader'];
		else
		{
			$h = array ('#' => '#', 'fullName' => 'Osoba',
									'docNumber' => '_Doklad', 's1' => ' VS', 's2' => ' SS', 'date' => 'Splatnost',
									'curr' => 'Měna', 'request' => ' Předpis', 'payment' => ' Uhrazeno', 'rest' => ' Zbývá',
									'debsAccountId' => 'Účet');
		}

		if ($this->reportParams['person']['value'] != '0')
		{
			unset ($h['fullName']);
			$tablePersons = new \E10\Persons\TablePersons ($this->app);
			$person = array ('ndx' => $this->reportParams['person']['value']);
			$this->setInfo('param', 'Osoba', $tablePersons->loadItem ($person)['fullName']);
		}

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE, 'params' => array ('disableZeros' => 1)));

		$this->setInfo('title', $thisBalanceDef['name']);
		$this->setInfo('icon', $thisBalanceDef['icon']);

		if (isset ($params ['fiscalYear']['value']))
			$this->setInfo('param', 'Období', $this->reportParams ['fiscalYear']['activeTitle']);
		else
		{
			$this->setInfo('param', 'Období', $this->dataFiscalYear['fullName']);
			if ($this->fiscalMonth > 0)
				$this->setInfo('param', 'Ke dni', utils::datef ($this->dataFiscalMonth['end'], '%D'));
		}
		if ($this->balanceKind != '0')
			$this->setInfo('param', 'Druh salda', $this->reportParams ['balanceKind']['activeTitle']);

		if ($this->displayItem != '0')
			$this->setInfo('param', 'Zobrazeno', $this->reportParams ['displayItem']['activeTitle']);

		$saveFileName = $thisBalanceDef['name'];
		if (isset ($params ['fiscalYear']['value']))
			$saveFileName .= ' '.$this->reportParams ['fiscalYear']['activeTitle'];
		else
		{
			if ($this->fiscalMonth > 0)
				$saveFileName .= ' ke dni '.str_replace ('.', '-', utils::datef ($this->dataFiscalMonth['end'], '%D'));
			else
				$saveFileName .= ' '.$this->dataFiscalYear['fullName'];
		}
		if ($this->balanceKind != '0')
			$saveFileName .= ' - '.$this->reportParams ['balanceKind']['activeTitle'];
		if ($this->displayItem != '0')
			$saveFileName .= ' - '.$this->reportParams ['displayItem']['activeTitle'];
		$this->setInfo('saveFileName', $saveFileName);

		$this->paperOrientation = 'landscape';
	}

	function createContent_DefaultHc ()
	{
		$this->mode = self::bmHomeCurrency;

		$data = $this->prepareData();

		$thisBalanceDef = $this->balances[$this->balance];

		if (isset ($thisBalanceDef['tableHeader']))
			$h = $thisBalanceDef['tableHeader'];
		else
		{
			$h = array ('#' => '#', 'fullName' => 'Osoba',
				'docNumber' => '_Doklad', 's1' => ' VS', 's2' => ' SS', 'date' => 'Splatnost',
				'requestHc' =>  ' Předpis DM', 'paymentHc' => ' Uhrazeno DM', 'restHc' => ' Zbývá DM', 'debsAccountId' => 'Účet');
		}

		if ($this->reportParams['person']['value'] != '0')
		{
			unset ($h['fullName']);
			$tablePersons = new \E10\Persons\TablePersons ($this->app);
			$person = array ('ndx' => $this->reportParams['person']['value']);
			$this->setInfo('param', 'Osoba', $tablePersons->loadItem ($person)['fullName']);
		}

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE, 'params' => array ('disableZeros' => 1)));

		$this->setInfo('title', $thisBalanceDef['name']);
		$this->setInfo('icon', $thisBalanceDef['icon']);
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalYear']['activeTitle']);
		if ($this->balanceKind != '0')
			$this->setInfo('param', 'Druh salda', $this->reportParams ['balanceKind']['activeTitle']);

		$saveFileName = $thisBalanceDef['name'].' '.$this->reportParams ['fiscalYear']['activeTitle'];
		if ($this->balanceKind != '0')
			$saveFileName .= ' - '.$this->reportParams ['balanceKind']['activeTitle'];
		$this->setInfo('saveFileName', $saveFileName);

		$this->setInfo('note', '1', 'Všechny částky jsou uvedeny v domácí měně (DM)');
		$this->paperOrientation = 'landscape';
	}

	function createContent_DefaultQ ()
	{
		$this->mode = self::bmQuantity;

		$data = $this->prepareData();

		$thisBalanceDef = $this->balances[$this->balance];

		if (isset ($thisBalanceDef['tableHeader']))
			$h = $thisBalanceDef['tableHeader'];
		else
		{
			$h = array ('#' => '#', 'docNumber' => 'Doklad',
									'fullName' => 'Osoba', 'itemName' => 'Položka');

			$h ['requestQ'] =  ' Dodáno';
			$h ['unit'] =  'Jed.';
			$h ['paymentQ'] = ' Vyfakturováno';
			$h ['restQ'] = ' Zbývá';
		}

		if ($this->reportParams['person']['value'] != '0')
		{
			unset ($h['fullName']);
			$tablePersons = new \E10\Persons\TablePersons ($this->app);
			$person = array ('ndx' => $this->reportParams['person']['value']);
			$this->setInfo('param', 'Osoba', $tablePersons->loadItem ($person)['fullName']);
		}

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE, 'params' => array ('disableZeros' => 1)));

		$this->setInfo('title', $thisBalanceDef['name']);
		$this->setInfo('icon', $thisBalanceDef['icon']);
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalYear']['activeTitle']);
		if ($this->balanceKind != '0')
			$this->setInfo('param', 'Druh salda', $this->reportParams ['balanceKind']['activeTitle']);

		$saveFileName = $thisBalanceDef['name'].' '.$this->reportParams ['fiscalYear']['activeTitle'];
		if ($this->balanceKind != '0')
			$saveFileName .= ' - '.$this->reportParams ['balanceKind']['activeTitle'];
		$this->setInfo('saveFileName', $saveFileName);

		$this->paperOrientation = 'landscape';
	}

	function createContent_Analysis ()
	{
		$thisBalanceDef = $this->balances[$this->balance];
		$data = $this->prepareData();

		$h = array ('#' => '#', 'fullName' => 'Osoba',
				'docNumber' => 'Doklad', 's1' => ' VS', 's2' => ' SS', 'date' => 'Splatnost', 'curr' => 'Měna',
				'rest0' => ' ≤0', 'rest1' => ' 1≏30', 'rest2' => ' 31≏90', 'rest3' => ' 91≏180', 'rest4' => ' 181≏360', 'rest5' => ' ≥361', 'rest' => ' Celkem',
				'debsAccountId' => 'Účet');

		if ($this->reportParams['person']['value'] != '0')
		{
			unset ($h['fullName']);
			$tablePersons = new \E10\Persons\TablePersons ($this->app);
			$person = array ('ndx' => $this->reportParams['person']['value']);
			$this->setInfo('param', 'Osoba', $tablePersons->loadItem ($person)['fullName']);
		}

		$this->addContent (array ('type' => 'table', 'header' => $h, 'main' => TRUE, 'table' => $data, 'params' => array ('disableZeros' => 1)));

		$this->setInfo('title', $thisBalanceDef['name']);
		$this->setInfo('icon', $thisBalanceDef['icon']);
		if ($this->balanceKind != '0')
			$this->setInfo('param', 'Druh salda', $this->reportParams ['balanceKind']['activeTitle']);

		$this->setInfo('title', $thisBalanceDef['name']);
		$this->setInfo('icon', $thisBalanceDef['icon']);
		if ($this->balanceKind != '0')
			$this->setInfo('param', 'Druh salda', $this->reportParams ['balanceKind']['activeTitle']);

		if (isset ($params ['fiscalYear']['value']))
			$this->setInfo('param', 'Období', $this->reportParams ['fiscalYear']['activeTitle']);
		else
		{
			$this->setInfo('param', 'Období', $this->dataFiscalYear['fullName']);
			if ($this->fiscalMonth > 0)
				$this->setInfo('param', 'Ke dni', utils::datef ($this->dataFiscalMonth['end'], '%D'));
		}
		if ($this->balanceKind != '0')
			$this->setInfo('param', 'Druh salda', $this->reportParams ['balanceKind']['activeTitle']);

		$saveFileName = $thisBalanceDef['name'];
		if (isset ($params ['fiscalYear']['value']))
			$saveFileName .= ' '.$this->reportParams ['fiscalYear']['activeTitle'];
		else
		{
			if ($this->fiscalMonth > 0)
				$saveFileName .= ' ke dni '.str_replace ('.', '-', utils::datef ($this->dataFiscalMonth['end'], '%D'));
			else
				$saveFileName .= ' '.$this->dataFiscalYear['fullName'];
		}
		if ($this->balanceKind != '0')
			$saveFileName .= ' - '.$this->reportParams ['balanceKind']['activeTitle'];
		$this->setInfo('saveFileName', $saveFileName);

		$this->paperOrientation = 'landscape';
	}

	function createContent_ExchangeDifferencies ()
	{
		$this->mode = self::bmExchangeDifference;
		$data = $this->prepareData();

		$thisBalanceDef = $this->balances[$this->balance];

		if (isset ($thisBalanceDef['tableHeader']))
			$h = $thisBalanceDef['tableHeader'];
		else
		{
			$h = array ('#' => '#', 'fullName' => 'Osoba',
															'docNumber' => 'Doklad', 's1' => ' VS', 's2' => ' SS', 'date' => 'Splatnost',
															'request' => ' Částka CM', 'curr' => 'Měna',
															'requestHc' =>  ' Předpis DM', 'paymentHc' => ' Uhrazeno DM', 'restHc' => ' Zbývá DM',
															'debsAccountId' => 'Účet');
		}

		if ($this->reportParams['person']['value'] != '0')
		{
			unset ($h['fullName']);
			$tablePersons = new \E10\Persons\TablePersons ($this->app);
			$person = array ('ndx' => $this->reportParams['person']['value']);
			$this->setInfo('param', 'Osoba', $tablePersons->loadItem ($person)['fullName']);
		}

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE, 'params' => array ('disableZeros' => 1)));

		$this->setInfo('title', 'Kurzové rozdíly / '.$thisBalanceDef['name']);
		$this->setInfo('icon', $thisBalanceDef['icon']);
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalYear']['activeTitle']);
		if ($this->balanceKind != '0')
			$this->setInfo('param', 'Druh salda', $this->reportParams ['balanceKind']['activeTitle']);

		$saveFileName = 'Kurzové rozdíly - '.$thisBalanceDef['name'].' '.$this->reportParams ['fiscalYear']['activeTitle'];
		if ($this->balanceKind != '0')
			$saveFileName .= ' - '.$this->reportParams ['balanceKind']['activeTitle'];
		$this->setInfo('saveFileName', $saveFileName);

		$this->setInfo('note', '1', 'CM - cizí měna, DM - domácí měna');
		$this->setInfo('note', '2', 'Zobrazují se pouze saldokontní případy vyrovnané v cizí měně');
		$this->paperOrientation = 'landscape';
	}

	function fiscalMonthsRB ($endMonth)
	{
		$endMonthRec = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE ndx = %i", $endMonth)->fetch ();
		$months = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE fiscalYear = %i AND [globalOrder] <= %i",
			$endMonthRec['fiscalYear'], $endMonthRec['globalOrder']);

		$monthList = array();
		forEach ($months as $m)
			$monthList[] = $m['ndx'];

		return $monthList;
	}

	function docIcon($r)
	{
		if ($r['paymentMethod'] != 0)
			return $this->paymentMethods[$r['paymentMethod']]['icon'];
		return $this->docTypes[$r['docType']]['icon'];
	}

	function prepareData ()
	{
		$q [] = 'SELECT heads.docNumber, heads.docType, heads.paymentMethod, persons.fullName, saldo.*, saldo.symbol1 as symbol1, saldo.symbol2 as symbol2, ';

		if ($this->mode == self::bmQuantity)
			array_push ($q, ' items.fullName as itemName, unit, ');

		array_push ($q, ' saldo.currency as currency FROM e10doc_balance_journal as saldo');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON saldo.person = persons.ndx');
		array_push ($q, '	LEFT JOIN e10doc_core_heads as heads ON saldo.docHead = heads.ndx');

		if ($this->mode == self::bmQuantity)
			array_push ($q, '	LEFT JOIN e10_witems_items as items ON saldo.item = items.ndx');


		array_push ($q, ' WHERE saldo.[fiscalYear] = %i', $this->fiscalYear);

		if ($this->fiscalMonth > 0)
			array_push ($q, " AND heads.[fiscalMonth] IN %in", $this->fiscalMonthsRB ($this->fiscalMonth));

		if ($this->reportParams['person']['value'] != '0')
			array_push($q, " AND saldo.[person] = %i", $this->reportParams['person']['value']);
		if ($this->personNdx)
			array_push($q, " AND saldo.[person] = %i", $this->personNdx);

		if (PHP_SAPI !== 'cli' && !$this->app->hasRole ('finance') && !$this->app->hasRole ('bsass') && !$this->app->hasRole ('audit'))
		{
			$ubg = E10Utils::usersBalancesGroups($this->app);
			if ($ubg !== FALSE)
			{
				array_push($q, ' AND EXISTS (SELECT ndx FROM e10_persons_personsgroups as pg WHERE saldo.person = pg.person');
				array_push($q, ' AND pg.[group] IN %in)', $ubg);
			}
			else
				array_push($q, " AND saldo.[person] = %i", -1);
		}

		array_push ($q, ' AND EXISTS (');
		array_push ($q, ' SELECT pairId, sum(amountHc) as amount, ',
										' sum(requestHc) as requestHc, sum(paymentHc) as paymentHc,');

		if ($this->mode == self::bmQuantity)
			array_push ($q, ' sum(requestQ) as requestQ, sum(paymentQ) as paymentQ,');

		array_push ($q, ' sum(request) as request, sum(payment) as payment');

		if ($this->fiscalMonth > 0)
			array_push ($q, ' FROM e10doc_balance_journal as j LEFT JOIN e10doc_core_heads as h ON j.docHead = h.ndx',
											' WHERE j.[type] = %i', $this->balance,
											' AND j.pairId = saldo.pairId AND j.[fiscalYear] = %i', $this->fiscalYear,
											' AND h.[fiscalMonth] IN %in', $this->fiscalMonthsRB ($this->fiscalMonth));
		else
			array_push ($q, ' FROM e10doc_balance_journal as j',
				' WHERE j.[type] = %i', $this->balance, ' AND j.pairId = saldo.pairId AND j.[fiscalYear] = %i ', $this->fiscalYear);

		if ($this->mode == self::bmExchangeDifference)
			array_push ($q, ' AND j.[currency] != %s', 'czk');

		array_push ($q, ' GROUP BY j.pairId');

		array_push ($q, ' HAVING ');
		if ($this->mode == self::bmExchangeDifference)
			array_push ($q, '[request] = [payment] AND [requestHc] != [paymentHc]');
		else
		if ($this->mode == self::bmHomeCurrency)
			array_push ($q, '[requestHc] != [paymentHc]');
		else
		if ($this->mode == self::bmQuantity)
			array_push ($q, '[requestQ] != [paymentQ]');
		else
			array_push ($q, '[request] != [payment]');

		array_push ($q, ')');

		$sortColumnName = '';
		if ($this->reportParams ['orderBy']['value'] == 'byDateDue')
		{
			array_push ($q, ' ORDER BY saldo.[date], persons.[fullName], [side], pairId');
			$sortColumnName = 'date';
			$this->pidColumnName = 'date';
			$this->sortColumnName = 'date';
			}
		else
		{
			array_push ($q, ' ORDER BY persons.[fullName], [side], saldo.[date], pairId');
			$sortColumnName = 'fullName';
			$this->pidColumnName = 'person';
			$this->sortColumnName = 'fullName';
		}

		$rows = $this->app->db()->query ($q);
		$data = array ();
		forEach ($rows as $r)
		{
			$pid = $r['pairId'];
			if ($r['side'] == 0)
			{
				if (isset($data[$pid]))
				{
					$data[$pid]['request'] += $r['request'];
					$data[$pid]['requestHc'] += $r['requestHc'];
					$data[$pid]['requestQ'] += $r['requestQ'];
				}
				else
				{
					$item = array (
						'docNumber' => array ('text'=> $r['docNumber'], 'icon' => $this->docIcon($r), 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead']),
						'person' => $r['person'], 'fullName' => $r['fullName'],
						'date' => $r['date'],
						'request' => $r['request'], 'payment' => $r['payment'],
						'requestHc' => $r['requestHc'], 'paymentHc' => $r['paymentHc'],
						'requestQ' => $r['requestQ'], 'paymentQ' => $r['paymentQ'],
						'debsAccountId' => $r['debsAccountId'],
						's1' => $r['symbol1'], 's2' => $r['symbol2'], 's3' => $r['symbol3']);
					$item['curr'] = $this->currencies[$r['currency']]['shortcut'];

					if ($this->mode == self::bmQuantity)
					{
						$item['itemName'] = $r['itemName'];
						$item['unit'] = $r['unit'];
					}
					$data[$pid] = $item;
				}
			}
			else
			{
				if (isset($data[$pid]))
				{
					$data[$pid]['payment'] += $r['payment'];
					$data[$pid]['paymentHc'] += $r['paymentHc'];
					$data[$pid]['paymentQ'] += $r['paymentQ'];
				}
				else
				{
					$item = array (
						'docNumber' => array ('text'=> $r['docNumber'], 'icon' => $this->docIcon($r), 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead']),
						'person' => $r['person'], 'fullName' => $r['fullName'],
						'date' => $r['date'],
						'request' => $r['request'], 'payment' => $r['payment'],
						'requestHc' => $r['requestHc'], 'paymentHc' => $r['paymentHc'],
						'requestQ' => $r['requestQ'], 'paymentQ' => $r['paymentQ'],
						's1' => $r['symbol1'], 's2' => $r['symbol2'], 's3' => $r['symbol3']);
					$item['curr'] = $this->currencies[$r['currency']]['shortcut'];
					$data[$pid] = $item;
				}
			}
			$data[$pid]['rest'] = $data[$pid]['request'] - $data[$pid]['payment'];
			$data[$pid]['restHc'] = $data[$pid]['requestHc'] - $data[$pid]['paymentHc'];
			$data[$pid]['restQ'] = $data[$pid]['requestQ'] - $data[$pid]['paymentQ'];

			$now = strtotime (date ('Ymd', time()));
			if ($this->fiscalMonth > 0)
				$now = strtotime ($this->dataFiscalMonth['end']->format('Ymd'));
			else
				if ($now > strtotime ($this->dataFiscalYear['end']->format('Ymd')))
					$now = strtotime ($this->dataFiscalYear['end']->format('Ymd'));

			$actDate = $now;
			if (isset($data[$pid]['date']) && $data[$pid]['date'] !== '')
				$actDate = strtotime ($data[$pid]['date']->format('Ymd'));

			$datediff = $now - $actDate;
			$days = floor ($datediff/(60*60*24));

			$data[$pid]['rest0'] = 0.0;
			$data[$pid]['rest1'] = 0.0;
			$data[$pid]['rest2'] = 0.0;
			$data[$pid]['rest3'] = 0.0;
			$data[$pid]['rest4'] = 0.0;
			$data[$pid]['rest5'] = 0.0;

			if ($days < 1)
				$data[$pid]['rest0'] = $data[$pid]['rest'];
			else
			{
				if ($this->reportParams ['balanceOverDue']['value'] == 'highlight' && $days > 6)
					$data[$pid]['_options']['class'] = E10Utils::balanceOverDueClass ($this->app, $days);
				if ($days < 31)
					$data[$pid]['rest1'] = $data[$pid]['rest'];
				else
				if ($days < 91)
					$data[$pid]['rest2'] = $data[$pid]['rest'];
				else
				if ($days < 181)
					$data[$pid]['rest3'] = $data[$pid]['rest'];
				else
				if ($days < 361)
					$data[$pid]['rest4'] = $data[$pid]['rest'];
				else
					$data[$pid]['rest5'] = $data[$pid]['rest'];
			}
		}

		$oldData = $data;
		$data = array ();
		$subTotals = array ();
		$totals = array ('fullName' => 'Celkem', 'enforce' => 1);

		$lastSortValue = FALSE;
		$lastSortValueCnt = 0;
		forEach ($oldData as $itemId => &$itemValue)
		{
			$paymentId = $this->balance.'-'.$itemValue['person'].'-'.$itemValue['s1'].'-'.$itemValue['s2'];
			if (isset ($this->paymentInfo[$paymentId]))
			{
				$paymentInfo = [];
				foreach ($this->paymentInfo[$paymentId] as $pi)
				{
					$paymentInfo[] = [
						'text' => utils::nf($pi['amount'], 2), 'class' => 'e10-row-plus e10-tag e10-small nowrap',
						'prefix' => 'PP '.utils::datef ($pi['dateDue']),
						'suffix' => $this->currencies[$pi['currency']]['shortcut']
					];
				}
				$itemValue['_options']['cellExtension']['fullName'] = $paymentInfo;
			}
			if (!isset ($itemValue['debsAccountId']))
				$itemValue['debsAccountId'] = '';

			// -- filter unpaired payments
			if ($this->balanceKind == '0' && $itemValue['debsAccountId'] == '' && $this->reportParams ['unpairedPayments']['value'] == 'hide')
				continue;

			// -- filter balance kind
			if ($this->balanceKind != '0' &&
				($itemValue['debsAccountId'] != $this->balanceKind && $itemValue['debsAccountId'] != '') ||
				($itemValue['debsAccountId'] == '' && $this->reportParams ['unpairedPayments']['value'] == 'hide'))
					continue;

			// -- filter display items
			if ($this->displayItem!= '0')
			{
				$actDate = $now;
				if (isset($itemValue['date']) && $itemValue['date'] !== '')
					$actDate = strtotime ($itemValue['date']->format('Ymd'));

				$datediff = $now - $actDate;
				$days = floor ($datediff/(60*60*24));
				$applyFilter = FALSE;
				switch ($this->displayItem)
				{
					case '10':
						if ($days < 0)
							$applyFilter = TRUE;
						break;
					case '11':
						if ($days < 0 || isset($itemValue['_options']['cellExtension']))
							$applyFilter = TRUE;
						break;
					case '20':
						if ($days >= 0)
							$applyFilter = TRUE;
						break;
					case '30':
						if (!isset($itemValue['_options']['cellExtension']))
							$applyFilter = TRUE;
						break;
				}
				if ($applyFilter == TRUE)
					continue;
			}

			if ($lastSortValue === $itemValue[$sortColumnName])
				$lastSortValueCnt++;
			if ($lastSortValue != $itemValue[$sortColumnName] && $lastSortValueCnt === 0)
			{
				if ($lastSortValue !== FALSE)
					$itemValue ['_options']['beforeSeparator'] = 'separator';
				$lastSortColumnCnt = 0;
			}

			$pid = $itemId;
			if (!isset($subTotals[$sortColumnName]) || $subTotals[$sortColumnName] != $itemValue[$sortColumnName])
			{
				$this->insertSubTotals ($subTotals, $data, $this->sortColumnName);
				$subTotals = array ($sortColumnName => $itemValue[$sortColumnName], 'pairIds' => array ($pid => 1));

				$lastSortValueCnt = 0;
			}
			else
			{
				$subTotals['pairIds'][$pid] = 1;
			}

			if ($this->mode == self::bmHomeCurrency)
				$curr = 'CZK';
			else
				if ($this->mode == self::bmQuantity)
					$curr = $itemValue['unit'];
				else
					$curr = $itemValue['curr'];
			$this->incSubTotals($subTotals, $itemValue, $curr, $this->pidColumnName, $this->sortColumnName, TRUE);
			$this->incSubTotals($totals, $itemValue, $curr, $this->pidColumnName, $this->sortColumnName);
			$data[$itemId] = $itemValue;

			$lastSortValue = $itemValue[$sortColumnName];
		}

		if (isset ($itemId) && isset ($data[$itemId]) && $lastSortValueCnt === 0)
			$data[$itemId]['_options']['afterSeparator'] = 'separator';

		if ($lastSortValue !== FALSE)
		{
			$this->insertSubTotals ($subTotals, $data, $this->sortColumnName);
			$this->insertSubTotals ($totals, $data, $this->sortColumnName, TRUE);
		}

		return $data;
	}

	function incSubTotals (&$subTotals, $itemValue, $curr, $pidColumnName, $sortColumnName, $toSortColumn = FALSE)
	{
		if ($this->disableSums)
			return;

		if (isset($subTotals['subTotal'][$curr]))
		{
			$subTotals['subTotal'][$curr]['request'] += $itemValue['request'];
			$subTotals['subTotal'][$curr]['requestHc'] += $itemValue['requestHc'];
			$subTotals['subTotal'][$curr]['requestQ'] += $itemValue['requestQ'];
			$subTotals['subTotal'][$curr]['payment'] += $itemValue['payment'];
			$subTotals['subTotal'][$curr]['paymentHc'] += $itemValue['paymentHc'];
			$subTotals['subTotal'][$curr]['paymentQ'] += $itemValue['paymentQ'];
			$subTotals['subTotal'][$curr]['rest'] += $itemValue['rest'];
			$subTotals['subTotal'][$curr]['restHc'] += $itemValue['restHc'];
			$subTotals['subTotal'][$curr]['restQ'] += $itemValue['restQ'];
			$subTotals['subTotal'][$curr]['rest0'] += $itemValue['rest0'];
			$subTotals['subTotal'][$curr]['rest1'] += $itemValue['rest1'];
			$subTotals['subTotal'][$curr]['rest2'] += $itemValue['rest2'];
			$subTotals['subTotal'][$curr]['rest3'] += $itemValue['rest3'];
			$subTotals['subTotal'][$curr]['rest4'] += $itemValue['rest4'];
			$subTotals['subTotal'][$curr]['rest5'] += $itemValue['rest5'];
		}
		else
		{
			$subTotals['subTotal'][$curr] = array (
				'request' => $itemValue['request'], 'requestHc' => $itemValue['requestHc'], 'requestQ' => $itemValue['requestQ'],
				'payment' => $itemValue['payment'], 'paymentHc' => $itemValue['paymentHc'], 'paymentQ' => $itemValue['paymentQ'],
				'rest' => $itemValue['rest'], 'restHc' => $itemValue['restHc'], 'restQ' => $itemValue['restQ'],
				'rest0' => $itemValue['rest0'], 'rest1' => $itemValue['rest1'], 'rest2' => $itemValue['rest2'],
				'rest3' => $itemValue['rest3'], 'rest4' => $itemValue['rest4'], 'rest5' => $itemValue['rest5']
			);
		}

		if ($toSortColumn)
		{
			$pid = json_encode($itemValue[$pidColumnName]);
			if (!isset($this->dataSubTotals[$pid]))
				$this->dataSubTotals[$pid] = [$sortColumnName => $itemValue[$sortColumnName], 'restHc' => 0.0, 'totals' => []];
			if (!isset($this->dataSubTotals[$pid]['totals'][$curr]))
				$this->dataSubTotals[$pid]['totals'][$curr] = [
						'rest' => 0.0, 'rest0' => 0.0, 'rest1' => 0.0, 'rest2' => 0.0, 'rest3' => 0.0, 'rest4' => 0.0, 'rest5' => 0.0
				];

			$this->dataSubTotals[$pid]['restHc'] += $itemValue['restHc'];
			$this->dataSubTotals[$pid]['totals'][$curr]['rest'] += $itemValue['rest'];
			$this->dataSubTotals[$pid]['totals'][$curr]['rest0'] += $itemValue['rest0'];
			$this->dataSubTotals[$pid]['totals'][$curr]['rest1'] += $itemValue['rest1'];
			$this->dataSubTotals[$pid]['totals'][$curr]['rest2'] += $itemValue['rest2'];
			$this->dataSubTotals[$pid]['totals'][$curr]['rest3'] += $itemValue['rest3'];
			$this->dataSubTotals[$pid]['totals'][$curr]['rest4'] += $itemValue['rest4'];
			$this->dataSubTotals[$pid]['totals'][$curr]['rest5'] += $itemValue['rest5'];
		}
	}

	function insertSubTotals ($subTotals, &$data, $sortColumnName, $end = FALSE)
	{
		if ($this->disableSums)
			return;

		if (isset($subTotals['enforce']) || (isset($subTotals['pairIds']) && count($subTotals['pairIds']) > 1))
		{
			$subTotalCnt = 0;
			forEach ($subTotals['subTotal'] as $key => $s)
			{
				$data[$subTotals[$sortColumnName].$key] = [$sortColumnName => $subTotals[$sortColumnName],
					'request' => $s['request'], 'requestHc' => $s['requestHc'], 'requestQ' => $s['requestQ'],
					'payment' => $s['payment'], 'paymentHc' => $s['paymentHc'], 'paymentQ' => $s['paymentQ'],
					'rest' => $s['rest'], 'restHc' => $s['restHc'], 'restQ' => $s['restQ'],
					'rest0' => $s['rest0'], 'rest1' => $s['rest1'], 'rest2' => $s['rest2'],
					'rest3' => $s['rest3'], 'rest4' => $s['rest4'], 'rest5' => $s['rest5'],
					'_options' => ['colSpan' => (($this->reportParams ['orderBy']['value'] == 'byDateDue')?['fullName' => 4]:['docNumber' => 4])],
				];

				$data[$subTotals[$sortColumnName].$key]['curr'] = $key;
				if (!$end)
				{
					if ($subTotalCnt+1 == count ($subTotals['subTotal']))
						$data[$subTotals[$sortColumnName].$key]['_options']['afterSeparator'] = 'separator';
					$data[$subTotals[$sortColumnName].$key]['_options']['class'] = 'subtotal';
				}
				else
				{
					$data[$subTotals[$sortColumnName].$key]['_options']['class'] = 'sumtotal';
					$data[$subTotals[$sortColumnName].$key]['_options']['colSpan'] = ['fullName' => 5];
				}
				$subTotalCnt++;
			}
		}
	}

	public function loadPaymentInfo () {}

	public function createToolbar ()
	{
		$buttons = parent::createToolbar();
		if ($this->app()->hasRole('root'))
			$buttons[] = ['text' => 'Přepočítat', 'icon' => 'icon-cog', 'type' => 'panelaction', 'action' => 'e10doc.balance.balanceRecalc', 'class' => 'btn-danger'];
		return $buttons;
	}
} // class reportBalance


/**
 * Class reportBalanceReceivables
 * @package E10Doc\Balance
 *
 * Saldokonto pohledávek
 */
class reportBalanceReceivables extends reportBalance
{
	function init ()
	{
		$this->balance = 1000;
		parent::init();
	}

	public function subReportsList ()
	{
		$d[] = array ('id' => 'items', 'icon' => 'detailBalanceByItems', 'title' => 'Položkově');
		$d[] = array ('id' => 'analysis', 'icon' => 'detailBalanceDueAnalysis', 'title' => 'Rozbor splatností');
		$d[] = array ('id' => 'exchDiffs', 'icon' => 'detailBalanceRatesDifferences', 'title' => 'Kurzové rozdíly');
		return $d;
	}
}


/**
 * Class reportBalanceObligations
 * @package E10Doc\Balance
 *
 * Saldokonto závazků
 */
class reportBalanceObligations extends reportBalance
{
	function init ()
	{
		$this->balance = 2000;
		parent::init();
	}

	public function subReportsList ()
	{
		$d[] = array ('id' => 'items', 'icon' => 'detailBalanceByItems', 'title' => 'Položkově');
		$d[] = array ('id' => 'analysis', 'icon' => 'detailBalanceDueAnalysis', 'title' => 'Rozbor splatností');
		$d[] = array ('id' => 'exchDiffs', 'icon' => 'detailBalanceRatesDifferences', 'title' => 'Kurzové rozdíly');
		return $d;
	}

	public function loadPaymentInfo ()
	{
		$q[] = 'SELECT [rows].*, heads.dateDue as dateDueHead FROM [e10doc_core_rows] AS [rows]';
		array_push($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND heads.docType = %s', 'bankorder');
		array_push($q, ' AND heads.docStateMain <= %i', 2);

		array_push($q, ' ORDER BY heads.dateAccounting DESC, [rows].ndx');
		array_push($q, ' LIMIT 1000');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$paymentId = $this->balance.'-'.$r['person'].'-'.$r['symbol1'].'-'.$r['symbol2'];
			$paymentInfo = ['amount' => $r['priceAll'], 'currency' => $r['currency']];

			if (!utils::dateIsBlank($r['dateDue']))
				$paymentInfo['dateDue'] = $r['dateDue'];
			else
				$paymentInfo['dateDue'] = $r['dateDueHead'];

			$this->paymentInfo[$paymentId][] = $paymentInfo;
		}
	}
}


/**
 * Class reportBalanceDepositReceived
 * @package E10Doc\Balance
 */
class reportBalanceDepositReceived extends reportBalance
{
	function init ()
	{
		$this->balance = 3000;
		parent::init();
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'items', 'icon' => 'detailBalanceByItems', 'title' => 'Položkově'];
		$d[] = ['id' => 'exchDiffs', 'icon' => 'detailBalanceRatesDifferences', 'title' => 'Kurzové rozdíly'];
		return $d;
	}
}


/**
 * Class reportBalanceAdvance
 * @package E10Doc\Balance
 */
class reportBalanceAdvance extends reportBalance
{
	function init ()
	{
			$this->balance = 3500;
			parent::init();
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'items', 'icon' => 'detailBalanceByItems', 'title' => 'Položkově'];
		$d[] = ['id' => 'exchDiffs', 'icon' => 'detailBalanceRatesDifferences', 'title' => 'Kurzové rozdíly'];
		return $d;
	}
}


/**
 * Class reportBalanceCashInTransit
 * @package E10Doc\Balance
 */
class reportBalanceCashInTransit extends reportBalance
{
	function init ()
	{
		$this->balance = 4100;
		parent::init();
	}
}


/**
 * Class reportBalanceTransitionalAccounts
 * @package E10Doc\Balance
 */
class reportBalanceTransitionalAccounts extends reportBalance
{
	function init ()
	{
		$this->balance = 4500;
		parent::init();
	}
}


/**
 * reportBalanceOffsetting
 *
 */

class reportBalanceOffsetting extends \e10doc\core\libs\reports\GlobalReport
{
	public $fiscalYear = 0;
	var $currencies;

	function init ()
	{
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->addParam ('fiscalYear');

		parent::init();

		$this->fiscalYear = $this->reportParams ['fiscalYear']['value'];
	}

	function createContent ()
	{
		$q = "SELECT fullName, currency, IF(receivables>obligations, obligations, receivables) as offsetting FROM
					(
						SELECT fullName, currency, ABS(SUM(receivables)) as receivables, ABS(SUM(obligations)) as obligations FROM
						(
							SELECT p.fullName, b.currency,
							(b.request-b.payment)*IF(b.type=1000,1,0) as receivables,
							(b.request-b.payment)*IF(b.type=2000,1,0) as obligations
							FROM e10doc_balance_journal as b LEFT JOIN e10_persons_persons as p ON (b.person = p.ndx)
							WHERE type IN(1000,2000) AND fiscalYear = %i
						) AS b1
						GROUP BY fullName, currency
						HAVING (SUM(receivables) > 0 AND SUM(obligations) > 0) OR (SUM(receivables) < 0 AND SUM(obligations) < 0)
					) AS b2";

		$rows = $this->app->db()->query($q, $this->fiscalYear);

		$total = array ();
		foreach ($rows as $r)
		{
			$item = $r;
			$item['curr'] = $this->currencies[$r['currency']]['shortcut'];
			$data [] = $item->toArray();

			if (isset ($total[$r['curr']]))
				$total[$r['curr']] += $r['offsetting'];
			else
				$total[$r['curr']] = $r['offsetting'];
		}
		foreach ($total as $key => $t)
			$data [] = ['fullName' => 'Celkem', 'curr' => $key, 'offsetting' => $t, '_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator']];

		$h = array ('#' => '#', 'fullName' => 'Partner', 'curr' => 'Měna', 'offsetting' => ' Částka k započtení');

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE));

		$this->setInfo('title', 'Návrh možných zápočtů');
		$this->setInfo('icon', 'balance/balanceOffsetting');
		$this->setInfo('param', 'Rok', $this->reportParams ['fiscalYear']['activeTitle']);
		$this->setInfo('saveFileName', 'Návrh možných zápočtů');
		$this->setInfo('note', '1', 'Možný návrh zápočtů obsahuje vzájemné závazky a pohledávky s ohledem na měny.');
	}
}

/**
 * Class reportBalanceJournal
 * @package E10Doc\Balance
 */

class reportBalanceJournal extends \e10doc\core\libs\reports\GlobalReport
{
	function createContent ()
	{
		$this->addContent (['type' => 'viewer', 'table' => 'e10doc.balance.journal', 'viewer' => 'e10doc.balance.ViewJournalAll', 'params' => []]);
	}

	public function createToolbar () {return [];}
}

/**
 * Seznam dokladů k likvidaci
 *
 */

class BalanceDisposalViewer extends \E10\TableViewWidget
{
	protected $fiscalYear;
	protected $balance = 0;
	protected $documents = array();
	protected $docCheckBoxes = 0;
	protected $searchDocsByPaymentSymbols = 0;

	public function init ()
	{
		$this->fiscalYear = E10Utils::todayFiscalYear($this->app());
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem = $item;
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['fullName'];
		return $listItem;
	}

	public function selectRows ()
	{
		$q [] = 'SELECT * FROM e10_persons_persons as persons WHERE ';
		array_push ($q, ' EXISTS ('.
										' SELECT pairId, sum(amount) as amount, sum(request) as request, sum(payment) as payment'.
										' FROM `e10doc_balance_journal` as q'.
										' WHERE q.[type] = %i', $this->balance, " AND q.person = persons.ndx AND q.[fiscalYear] = %i ", $this->fiscalYear,
										' GROUP BY q.person having [request] != payment)');
		array_push ($q, ' ORDER BY persons.fullName');
		array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
		$q = [];
		array_push ($q, 'SELECT heads.docNumber as docNumber, heads.person as personNdx, heads.dateAccounting as dateAccounting,');
		array_push ($q, ' heads.weightNet as weightNet, persons.fullName, heads.ndx AS docHeadNdx, saldo.*');
		array_push ($q, ' FROM e10doc_balance_journal as saldo');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON saldo.person = persons.ndx');

		if ($this->searchDocsByPaymentSymbols)
			array_push ($q, ' LEFT JOIN e10doc_core_heads as heads ON saldo.symbol1 = heads.symbol1 AND saldo.person = heads.person');
		else
			array_push ($q, ' LEFT JOIN e10doc_core_heads as heads ON saldo.docHead = heads.ndx');

		array_push ($q, ' WHERE');
		array_push ($q, ' saldo.[fiscalYear] = %i AND ', $this->fiscalYear);
		array_push ($q, ' EXISTS ('.
										' SELECT pairId, sum(amount) as amount, sum(request) as request, sum(payment) as payment '.
										' FROM `e10doc_balance_journal` as q'.
										' WHERE q.[type] = %i', $this->balance,
										' AND q.pairId = saldo.pairId AND q.[fiscalYear] = %i ', $this->fiscalYear,
										' GROUP BY q.pairId having `request` != payment)');
		array_push ($q, ' ORDER BY saldo.side, persons.fullName, saldo.[date] DESC, pairId');

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$person = $r['personNdx'];
			if ($r['side'] === 0)
			{
				$this->documents [$person][$r['pairId']] = [
					'ndx' => $r['docHeadNdx'], 'docNumber' => $r['docNumber'], 'symbol1' => $r['symbol1'],
					'dateAccounting' => $r['dateAccounting'], 'toPay' => $r['amount'], 'weightNet' => $r['weightNet']
				];
			}
			else
			{
				$this->documents [$person][$r['pairId']]['toPay'] -= $r['amount'];
			}
		}
	}

	function decorateRow (&$item)
	{
		$person = $item ['pk'];
		$item['t2'] = '';
		$tiles = array();
		$toPay = 0.0;
		forEach ($this->documents[$person] as $p)
		{
			if (!isset ($p['ndx']))
				continue;

			$tile = array (
							't1' => array ('text' => $p['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $p['ndx']),
							't2' => utils::datef ($p['dateAccounting'], '%D'), 't3' => \E10\nf ($p['toPay'], 2),
							'docActionData' => array ('symbol1' => ($p['symbol1'] != '') ? $p['symbol1'] : $p['docNumber'],
																				'price' => $p['toPay'], 'date' => utils::datef ($p['dateAccounting'], '%D'))
							);

			$this->checkDocumentTile ($p, $tile);
			$tiles[] = $tile;
			$toPay += $p['toPay'];
		}

		$item['docActionData'] = array ('fillDocRows' => 'default', 'toPay' => $toPay, 'person' => $item['pk']);
		$item['tiles'] = $tiles;
		$item['i1'] = \E10\nf ($toPay, 2);
	}

	public function rowHtmlContent ($listItem)
	{
		$c = '';

		$c .= "<h2 style='padding-top: 1ex;'>";
		$c .= $this->app()->ui()->composeTextLine($listItem['t1']);
		if (isset ($listItem['i1']))
			$c .= "<span style='float: right;'>" . $this->app()->ui()->composeTextLine($listItem['i1']) . '</span>';
		$c .= '</h2>';

		if (isset ($listItem['tiles']))
			$c .= $this->rowHtmlContentTiles ($listItem);
		if (isset ($listItem['buttons']))
			$c .= $this->rowHtmlContentButtons ($listItem);

		if (isset ($listItem['docActionData']))
		{
			forEach ($listItem['docActionData'] as $ddId => $ddValue)
			{
				$c .= "<input type='hidden' name='docActionData.{$ddId}' value='$ddValue'/>";
			}
		}

		return $c;
	}

	public function rowHtmlContentButtons ($listItem)
	{
		$c = '';
		$c .= "<div style='border-left: 1px solid #aaa; border-right: 1px solid #aaa; border-bottom: 1px solid #aaa;background-color: #f5f5f5; text-align: right;'>";

		forEach ($listItem['buttons'] as $b)
		{
			$params = '';
			$class = 'btn';
			$style = '';
			if (isset ($b['docAction']))
			{
				$class .= ' btn-success e10-document-trigger';
				$params .= "data-action='{$b['docAction']}' data-table='{$b['table']}'";

				if (isset ($b['pk']))
					$params .= " data-pk='{$b['pk']}'";
				if (isset ($b['addParams']))
					$params .= " data-addparams='{$b['addParams']}'";
				if (isset ($b['data-class']))
					$params .= " data-class='{$b['data-class']}'";
			}

			$c .= "<div style='padding: 1ex; display: inline-block;'>";

			$c .= "<button class='$class'$params style='$style'>";
			$c .= $this->app()->ui()->composeTextLine($b['text']);
			$c .= '</button>';
			$c .= '</div>';
		}

		$c .= '</div>';

		return $c;
	}

	public function rowHtmlContentTiles ($listItem)
	{
		$c = '';
		$c .= "<div style='border-left: 1px solid #aaa; border-right: 1px solid #aaa; border-top: 1px solid #aaa;background-color: #f5f5f5; '>";
		$tileIndex = 1;

		forEach ($listItem['tiles'] as $t)
		{
			$c .= "<div style='padding: 1ex; width: 25%; display: inline-block;'>";

			$params = '';
			$class = '';

			$style = "border: 1px solid #333; height: 9ex; position: relative;";

			if (isset ($t['coverImage']))
				$style .= "background-image:url(\"" . $t['coverImage'] . "\"); background-size: cover;";
			$c .= "<div class='$class'$params style='$style'>";

			if ($this->docCheckBoxes !== 0)
			{
				$checked = (($this->docCheckBoxes === 1 && $tileIndex === 1) || ($this->docCheckBoxes === 2)) ? " checked='checked'" : '';
				$c .= "&nbsp;<input type='checkbox' name='docActionData.rows.$tileIndex.enabled' value='1'$checked/>";
			}

			$c .= "<div style='position: absolute; bottom: 0px; right: 0px; background-color: rgba(0,0,0,.5); color: white; width: 80%; height: 100%; padding: 3px; text-align: right;'>";
			$c .= $this->app()->ui()->composeTextLine($t['t1']);
			if (isset ($t['t2']))
				$c .= '<br/>'.$this->app()->ui()->composeTextLine($t['t2']);
			if (isset ($t['t3']))
				$c .= '<br/>'.$this->app()->ui()->composeTextLine($t['t3']);
			$c .= '</div>';

			$c .= '</div>';

			if (isset ($t['docActionData']))
			{
				forEach ($t['docActionData'] as $ddId => $ddValue)
				{
					$c .= "<input type='hidden' name='docActionData.rows.$tileIndex.{$ddId}' value='$ddValue'/>";
				}
			}

			$c .= '</div>';

			$tileIndex++;
		}

		$c .= '</div>';

		return $c;
	}

	public function checkDocumentTile ($document, &$tile)
	{
	}
}

/**
 * Class ViewDetailPersonsBalances
 * @package E10Doc\Balance
 */
class ViewDetailPersonsBalances extends \E10\TableViewDetail
{
	protected $fiscalYear = 0;
	protected $showBalances = array();
	protected $balances = [];
	protected $currencies;
	protected $docTypes;

	static $balTypeClass = ['c' => 'e10-row-minus', 'd' => 'e10-row-plus'];

	public function createDetailContent ()
	{
		$access = 0;
		if ($this->app()->hasRole ('finance') || $this->app()->hasRole ('bsass') || $this->app()->hasRole ('audit'))
			$access = 1;

		if (!$access)
		{
			$ubg = E10Utils::usersBalancesGroups($this->table->app());
			if ($ubg !== FALSE)
				if (E10Utils::personHasGroup($this->table->app(), $this->item['ndx'], $ubg))
					$access = 1;
		}

		if (!$access)
		{
			$sumTile ['info'][] = ['value' => ['text' => 'Nemáte oprávnění k zobrazení saldokonta této osoby.']];
			$this->addContent(['type' => 'tiles', 'tiles' => [$sumTile], 'class' => 'panes']);
			return;
		}

		$this->currencies = $this->app()->cfgItem ('e10.base.currencies');
		$this->balances = $this->app()->cfgItem ('e10.balance');
		foreach ($this->balances as $key => $b)
			$this->showBalances[] = intval($key);
		$this->docTypes = $this->table->app()->cfgItem ('e10.docs.types');

		$this->fiscalYear = E10Utils::todayFiscalYear($this->app());

		$data = $this->createBalanceDetail($this->item);

		// -- summary
		$sumTile = ['info' => []];

		$titleClass = 'e10-row-this';
		if ($data['totalAmount'] > 0.0)
			$titleClass = 'e10-row-plus';
		else
		if ($data['totalAmount'] < 0.0)
			$titleClass = 'e10-row-minus';

		$title = ['class' => 'title '.$titleClass, 'value' => [['text' => 'Celková bilance', 'class' => 'h2']]];

		if (count($data['totals']))
		{
			foreach ($data['totals'] as $total)
				$title['value'][] = ['text' => utils::nf ($total['amount'], 2), 'prefix' => $total['currency'], 'class' => 'h2 pull-right'];
		}
		else
		{
			$title['value'][] = ['text' => utils::nf (0.0, 2), 'class' => 'h2 pull-right'];
		}
		$sumTile ['info'][] = $title;


		if (count($data['rows']) == 0)
		{ // no results
			$sumTile ['info'][] = ['value' => ['text' => 'V aktuálním fiskálním roce nejsou žádné záznamy.']];
			$this->addContent(['type' => 'tiles', 'tiles' => [$sumTile], 'class' => 'panes']);
			return;
		}


		if (!isset ($data['sums']['c']) && !isset ($data['sums']['d']))
			$sumTile ['info'][] = ['value' => ['text' => 'Neexistují žádné neuhrazené saldokontní případy.']];
		else
		{
			if (isset ($data['sums']['d']))
				$sumTile ['info'][] = [
					'class' => 'info width50',
					'header' => ['name' => 'název', 'currency' => 'Měna', 'rest' => ' Částka'],
					'table' => $data['sums']['d'], 'params' => ['hideHeader' => 1, 'tableClass' => self::$balTypeClass['d']]
				];
			else
				$sumTile ['info'][] = ['class' => 'info width50', 'text' => ''];

			if (isset ($data['sums']['c']))
				$sumTile ['info'][] = [
					'class' => 'info width50',
					'header' => ['name' => 'název', 'currency' => 'Měna', 'rest' => ' Částka'],
					'table' => $data['sums']['c'], 'params' => ['hideHeader' => 1, 'tableClass' => self::$balTypeClass['c']]
				];
			else
				$sumTile ['info'][] = ['class' => 'info width50', 'text' => ''];
		}

		$this->addContent(['type' => 'tiles', 'tiles' => [$sumTile], 'class' => 'panes']);


		// -- rows
		$headerRows = ['#' => '#', 'icon' => ['icon' => 'system/iconStar', 'text' => ''], 'docNumber' => 'Doklad',
									 's1' => ' VS', 's2' => ' SS', 'date' => 'Splatnost', 'curr' => 'Měna',
									 'request' => ' Předpis',
									 'rest' => ' Zůstatek',
									 '_options' => ['cellClasses' => ['icon' => 'e10-icon']]
		];

		$this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
												'title' => ['icon' => 'system/iconBalance', 'text' => 'Saldokonto'],
												'header' => $headerRows, 'table' => $data['rows']]);
	}

	function createBalanceDetail ($person)
	{
		$q [] = 'SELECT heads.docNumber, heads.docType as docType, heads.activity as activity, heads.title, heads.dateAccounting, saldo.*';

		array_push ($q, ' FROM e10doc_balance_journal as saldo');
		array_push ($q, '	LEFT JOIN e10doc_core_heads as heads ON saldo.docHead = heads.ndx');
		array_push ($q, ' WHERE saldo.[fiscalYear] = %i', $this->fiscalYear);
		array_push ($q, " AND saldo.[person] = %i", $person['ndx']);
		array_push ($q, ' AND EXISTS (');
		array_push ($q, ' SELECT pairId, sum(request) as request, sum(payment) as payment');
		array_push ($q, ' FROM e10doc_balance_journal as j');
		array_push ($q, ' WHERE j.[type] IN %in', $this->showBalances);
		array_push ($q, ' AND j.pairId = saldo.pairId AND j.[fiscalYear] = %i ', $this->fiscalYear);
		array_push ($q, ' GROUP BY j.pairId');
		array_push ($q, ')');
		array_push ($q, ' ORDER BY [side], saldo.[date] DESC, pairId');

		$rows = $this->app()->db()->query ($q);
		$data = [];
		$sumTotal = [];
		$currenciesRateTotal = [];
		forEach ($rows as $r)
		{
			$pid = $r['pairId'];
			if (isset($this->balances[$r['type']]['type']) && $this->balances[$r['type']]['type'] === 'hc')
			{
				$r['currency'] = $r['homeCurrency'];
				$r['request'] = $r['requestHc'];
				$r['payment'] = $r['paymentHc'];
			}

			if ($r['side'] == 0)
			{
				if (isset($data[$pid]))
				{
					$data[$pid]['request'] += $r['request'];
					$item['exchangeRate'] = $r['requestHc']/$r['request'];
					$item['dateAccounting'] = $r['dateAccounting'];
				}
				else
				{
					$item = array (
						'type' => $r['type'], 'currency' => $r['currency'],
						'docNumber' => array ('text'=> $r['docNumber'], 'icon' => $this->docTypes[$r['docType']]['icon'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead']),
						'date' => $r['date'],
						'request' => $r['request'], 'payment' => $r['payment'],
						's1' => $r['symbol1'], 's2' => $r['symbol2']);
					$item['curr'] = $this->currencies[$r['currency']]['shortcut'];
					if ($r['request'] != 0.0)
						$item['exchangeRate'] = $r['requestHc']/$r['request'];
					else
						$item['exchangeRate'] = 1;
					$item['dateAccounting'] = $r['dateAccounting'];
					$item['icon'] = array ('text' => '', 'icon' => $this->balances[$r['type']]['icon']);
					$item['_options'] = [
						'cellTitles' => ['icon' => $this->balances[$r['type']]['name'], 'docNumber' => $this->docTitle($r)],
						'cellClasses' => ['icon' => 'e10-icon '.self::$balTypeClass[$this->balances[$r['type']]['side']]]
					];

					$data[$pid] = $item;
				}
			}
			else
			{
				if (isset($data[$pid]))
				{
					$data[$pid]['payment'] += $r['payment'];
					$item['exchangeRate'] = $r['paymentHc']/$r['payment'];
					$item['dateAccounting'] = $r['dateAccounting'];
				}
				else
				{
					$item = array (
						'type' => $r['type'], 'currency' => $r['currency'],
						'docNumber' => array ('text'=> $r['docNumber'], 'icon' => $this->docTypes[$r['docType']]['icon'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead']),
						'date' => $r['date'],
						'request' => $r['request'], 'payment' => $r['payment'],
						's1' => $r['symbol1'], 's2' => $r['symbol2']);
					$item['curr'] = $this->currencies[$r['currency']]['shortcut'];
					if ($r['payment'] != 0.0)
						$item['exchangeRate'] = $r['paymentHc']/$r['payment'];
					else
						$item['exchangeRate'] = 1;
					$item['dateAccounting'] = $r['dateAccounting'];
					$item['icon'] = array ('text' => '', 'icon' => $this->balances[$r['type']]['icon']);
					$item['_options'] = [
						'cellTitles' => ['icon' => $this->balances[$r['type']]['name'], 'docNumber' => $this->docTitle($r)],
						'cellClasses' => ['icon' => 'e10-icon '.self::$balTypeClass[$this->balances[$r['type']]['side']]]
					];

					$data[$pid] = $item;
				}
			}

			$data[$pid]['rest'] = $data[$pid]['request'] - $data[$pid]['payment'];

			if ($this->balances[$r['type']]['side'] == 'd')
				$data[$pid]['balanceDr'] = $data[$pid]['rest'];
			else
				$data[$pid]['balanceCr'] = $data[$pid]['rest'];
		}

		foreach ($data as $dataRow)
		{
			if ($dataRow['rest'] == 0.0)
				continue;

			if (!isset ($sumTotal[$dataRow['type']][$dataRow['currency']]))
				$sumTotal[$dataRow['type']][$dataRow['currency']] = ['rest' => 0.0, 'currency' => $dataRow['currency']];
			$sumTotal[$dataRow['type']][$dataRow['currency']]['rest'] += $dataRow['rest'];

			if (!isset ($currenciesRateTotal[$dataRow['currency']]))
				$currenciesRateTotal[$dataRow['currency']] = ['dateAccounting' => $dataRow['dateAccounting'], 'exchangeRate' => $dataRow['exchangeRate']];
			else
			{
				if ($dataRow['dateAccounting'] > /*$sumTotal[$dataRow['currency']]['dateAccounting']*/$currenciesRateTotal[$dataRow['currency']]['dateAccounting'])
				{
					$currenciesRateTotal[$dataRow['currency']]['dateAccounting'] = $dataRow['dateAccounting'];
					$currenciesRateTotal[$dataRow['currency']]['exchangeRate'] = $dataRow['exchangeRate'];
				}
			}
		}

		$sums = [];
		$total = [];
		$totalAmount = 0.0;
		foreach ($sumTotal as $balanceType => $balanceSums)
		{
			foreach ($balanceSums as $currId => $currSum)
			{
				$side = $this->balances[$balanceType]['side'];
				$s = ['currency' => $this->currencies[$currId]['shortcut'],
								'name' => array ('text' => $this->balances[$balanceType]['name'], 'icon' => $this->balances[$balanceType]['icon']),
								'rest' => $currSum['rest']];
				$sums [$side][] = $s;

				if (!isset ($total[$currId]))
					$total[$currId] = ['currency' => $this->currencies[$currId]['shortcut'], 'amount' => 0.0];

				if ($side === 'c')
				{
					$total[$currId]['amount'] -= $currSum['rest'];
					$totalAmount -= round ($currSum['rest']*$currenciesRateTotal[$currId]['exchangeRate'], 2);
				}
				else
				{
					$total[$currId]['amount'] += $currSum['rest'];
					$totalAmount += round ($currSum['rest']*$currenciesRateTotal[$currId]['exchangeRate'], 2);
				}
			}
		}

		return ['rows' => $data, 'sums' => $sums, 'totals' => $total, 'totalAmount' => $totalAmount];
	}

	protected function docTitle ($r)
	{
		$docTitle = $this->docTypes[$r['docType']]['fullName'];
		if ($r['title'] != '')
			$docTitle .= ': '.$r['title'];

		return $docTitle;
	}
}


/**
 * Class widgetBalanceReceivables
 * @package E10Doc\Balance
 */
class widgetBalanceReceivables extends \Shipard\UI\Core\WidgetPane
{
	var $report;

	function createContent ()
	{
		$this->report = new reportBalanceReceivables ($this->app);
		$this->report->init();
		$this->report->mode = reportBalance::bmHomeCurrency;

		$data = $this->report->prepareData();

		$h = ['fullName' => 'Osoba',
					'docNumber' => 'Doklad', 'date' => 'Splatnost',
					'requestHc' =>  ' Předpis', 'paymentHc' => ' Uhrazeno', 'restHc' => ' Zbývá'];

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE, 'params' => ['disableZeros' => 1]]);
	}
}

