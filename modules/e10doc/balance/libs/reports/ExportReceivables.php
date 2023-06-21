<?php

namespace e10doc\balance\libs\reports;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \Shipard\Utils\Utils, \Shipard\Utils\Str, \Shipard\Utils\World, \e10doc\core\e10utils;


/**
 * class ExportReceivables
 */
class ExportReceivables extends \e10doc\core\libs\reports\GlobalReport
{
	const bmNormal = 0, bmExchangeDifference = 1, bmHomeCurrency = 2, bmQuantity = 3;

  const etDefaultCSV = 0, etCeskaSporitelnaCSV = 1;

	public $fiscalYear = 0;
	protected $dataFiscalYear = [];
	protected $fiscalMonth = 0;
	protected $dataFiscalMonth = [];
	protected $balance = 1000;
	protected $docTypes;
	protected $paymentMethods;
	public $balances;
	var $currencies;
  var $totals;

	var $mode = self::bmNormal;

  var $totalsColSpan = 5;

	public $balanceKinds = [];
	var $balanceKind = '0';
	var $displayItem = '0';
	public $personSubTotals = [];

	protected $paymentInfo = [];

  var $endDate;
  var $exportTypes = [];
  var $exportTypeId = '';
  var $exportType = self::etDefaultCSV;
  var $exportText = '';
  var $totalAmount = 0;

	function init ()
	{
		$this->balance = 1000;

    $this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['years'], 'defaultValue' => 'Y'.E10Utils::todayFiscalYear($this->app)]);
    $this->createExportTypes();

		parent::init();

		$this->docTypes = $this->app->cfgItem ('e10.docs.types');
		$this->paymentMethods = $this->app->cfgItem ('e10.docs.paymentMethods');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->balances = $this->app->cfgItem ('e10.balance');
		$this->fiscalYear = $this->getFiscalYear($this->reportParams);

		$tableFiscalYear = new \E10Doc\Base\TableFiscalYears($this->app);
		$itemFiscalYear = array ('ndx' => $this->fiscalYear);
		$this->dataFiscalYear = $tableFiscalYear->loadItem ($itemFiscalYear);

    $this->exportTypeId = $this->reportParams ['exportType']['value'];
    $this->exportType = $this->exportTypes[$this->exportTypeId]['type'];

    if ($this->fiscalMonth > 0)
      $this->endDate = Utils::createDateTime ($this->dataFiscalMonth['end']);
    else
      $this->endDate = Utils::createDateTime ($this->dataFiscalYear['end']);

    $today = Utils::today();
    if ($this->endDate > $today)
      $this->endDate = $today;
	}

  function createExportTypes()
  {
    $enum = [];

    $allBankAccounts = $this->app()->cfgItem('e10doc.bankAccounts', []);
    foreach ($allBankAccounts as $ba)
    {
      if (isset($ba['options']) && isset($ba['options']['pledgeAgreementNumber']))
      {
        $id = 'CS_CSV_'.$ba['bankAccount'];
        $enum [$id] = 'ČS '.$ba['bankAccount'];
        $this->exportTypes[$id] = [
          'type' => self::etCeskaSporitelnaCSV,
          'saveAsTitle' => 'Uložit jako Pohledávky Česká spořitelna',
          'saveAsFileType' => 'csv',
          'options' => $ba['options']
        ];
      }
    }

    $this->exportTypes['default_csv'] = [
      'type' => self::etDefaultCSV,
      'saveAsTitle' => 'Uložit jako CSV soubor',
      'saveAsFileType' => 'csv',
    ];
    $enum ['default_csv'] = 'Obecný CSV soubor';

    $this->addParam('switch', 'exportType', ['title' => 'Export', 'switch' => $enum, 'defaultValue' => key($enum)]);
  }

  function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'items': $this->createContent_Default(); break;
			case 'export': $this->createContent_Export(); break;
		}
  }

  function createContent_Default ()
	{
		$thisBalanceDef = $this->balances[$this->balance];

		$data = $this->prepareData();
    $this->createExport($data);

		if (isset ($thisBalanceDef['tableHeader']))
			$h = $thisBalanceDef['tableHeader'];
		else
		{
			$h = [
        '#' => '#',
        'fullName' => 'Osoba',
        'address' => 'Sídlo',
        'country' => 'Z.',
        'oid' => 'IČ',
        'vatId' => 'DIČ',
        'docNumber' => '_Doklad', 's1' => ' VS', 's2' => ' SS',
        'dateIssue' => 'Vystaveno',
        'date' => 'Splatnost',
        'curr' => 'Měna', 'request' => ' Předpis', 'payment' => ' Uhrazeno', 'rest' => ' Zbývá',
        'restHc' => '+Zbývá CZK',
      ];
		}

    $this->totalAmount = 0;
    $dataFiltered = [];
    foreach ($data as $di)
    {
      if (!$this->rowIsEnabled($di))
        continue;

      $dataFiltered[] = $di;
    }

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $dataFiltered, 'main' => TRUE, 'params' => ['disableZeros' => 1]]);

		$this->setInfo('title', $thisBalanceDef['name']);
		$this->setInfo('icon', $thisBalanceDef['icon']);

    $this->setInfo('param', 'Období', $this->dataFiscalYear['fullName']);
    $this->setInfo('param', 'Ke dni', Utils::datef ($this->endDate, '%d'));

		$saveFileName = $thisBalanceDef['name'];
		if (isset ($params ['fiscalYear']['value']))
			$saveFileName .= ' '.$this->reportParams ['fiscalYear']['activeTitle'];
		else
		{
			if ($this->fiscalMonth > 0)
				$saveFileName .= ' ke dni '.str_replace ('.', '-', Utils::datef ($this->dataFiscalMonth['end'], '%d'));
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

  function createContent_Export ()
	{
		$thisBalanceDef = $this->balances[$this->balance];

		$data = $this->prepareData();
    $this->createExport($data);

    $this->addContent (['type' => 'text', 'subtype' => 'code', 'text' => $this->exportText]);

		$this->setInfo('title', $thisBalanceDef['name']);
		$this->setInfo('icon', $thisBalanceDef['icon']);
    $this->setInfo('param', 'Období', $this->dataFiscalYear['fullName']);
    $this->setInfo('param', 'Ke dni', Utils::datef ($this->endDate, '%d'));
  }

	function prepareData ()
	{
		$q [] = ' ';

    array_push($q, 'SELECT heads.docNumber, heads.docType, heads.paymentMethod, heads.dateIssue,');
    array_push($q, ' persons.fullName, saldo.*, saldo.symbol1 as symbol1, saldo.symbol2 as symbol2, ');

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

		array_push ($q, ' ORDER BY persons.[fullName], persons.[ndx], [side], saldo.[date], pairId');

		$rows = $this->app->db()->query ($q);
		$data = [];
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
					$item = [
						'docNumber' => [
              ['text'=> $r['docNumber'], 'icon' => $this->docIcon($r), 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead'], 'class' => ''],
            ],
            'docType' => $r['docType'],
						'person' => $r['person'],
            'fullName' => $r['fullName'],
						'dateIssue' => $r['dateIssue'], 'date' => $r['date'],
						'request' => $r['request'], 'payment' => $r['payment'],
						'requestHc' => $r['requestHc'], 'paymentHc' => $r['paymentHc'],
						'requestQ' => $r['requestQ'], 'paymentQ' => $r['paymentQ'],
						'debsAccountId' => $r['debsAccountId'],
						's1' => $r['symbol1'], 's2' => $r['symbol2'], 's3' => $r['symbol3']
          ];
					$item['curr'] = $this->currencies[$r['currency']]['shortcut'];

          if ($r['docType'] === 'cmnbkp')
          {
            $originalDoc = $this->app->db()->query ('SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s', 'invno',
              'AND symbol1 = %s', $r['symbol1'], ' AND symbol2 = %s', $r['symbol2'], ' AND person = %i', $r['person'])->fetch();
            if ($originalDoc)
            {
              $docNdx = $originalDoc['ndx'];
              $docNumber = $originalDoc['docNumber'];
              $item['docNumber'] = ['text'=> $docNumber, 'icon' => 'docType/invoicesOut', 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $docNdx, 'class' => ''];
              $item['dateIssue'] = $originalDoc['dateIssue'];
              $item['docType'] = $originalDoc['docType'];
            }
          }

          $this->checkPerson($item);
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
					$item = [
						'docNumber' => array ('text'=> $r['docNumber'], 'icon' => $this->docIcon($r), 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead']),
            'docType' => $r['docType'],
						'person' => $r['person'],
            'fullName' => $r['fullName'],
						'dateIssue' => $r['dateIssue'], 'date' => $r['date'],
						'request' => $r['request'], 'payment' => $r['payment'],
						'requestHc' => $r['requestHc'], 'paymentHc' => $r['paymentHc'],
						'requestQ' => $r['requestQ'], 'paymentQ' => $r['paymentQ'],
						's1' => $r['symbol1'], 's2' => $r['symbol2'], 's3' => $r['symbol3']
          ];
					$item['curr'] = $this->currencies[$r['currency']]['shortcut'];

          if ($r['docType'] === 'cmnbkp')
          {
            $originalDoc = $this->app->db()->query ('SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s', 'invno',
              'AND symbol1 = %s', $r['symbol1'], ' AND symbol2 = %s', $r['symbol2'], ' AND person = %i', $r['person'])->fetch();
            if ($originalDoc)
            {
              $docNdx = $originalDoc['ndx'];
              $docNumber = $originalDoc['docNumber'];
              $item['docNumber'][] = ['text'=> $docNumber, 'icon' => 'docType/invoicesOut', 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $docNdx];
              $item['dateIssue'] = $originalDoc['dateIssue'];
              $item['docType'] = $originalDoc['docType'];
            }
          }

          $this->checkPerson($item);
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
		}

		$oldData = $data;
		$data = [];
		$subTotals = [];
		$totals = ['fullName' => 'Celkem', 'enforce' => 1];

		$lastName = FALSE;
		$lastNameCnt = 0;
		forEach ($oldData as $itemId => &$itemValue)
		{
			$paymentId = $this->balance.'-'.$itemValue['person'].'-'.$itemValue['s1'].'-'.$itemValue['s2'];
			if (isset ($this->paymentInfo[$paymentId]))
			{
				$paymentInfo = [];
				foreach ($this->paymentInfo[$paymentId] as $pi)
				{
					$paymentInfo[] = [
						'text' => Utils::nf($pi['amount'], 2), 'class' => 'e10-row-plus e10-tag e10-small nowrap',
						'prefix' => 'PP '.Utils::datef ($pi['dateDue']),
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

			if ($lastName === $itemValue['fullName'])
				$lastNameCnt++;

			if ($lastName != $itemValue['fullName'] && $lastNameCnt === 0)
			{
				//if ($lastName !== FALSE)
				//	$itemValue ['_options']['beforeSeparator'] = 'separator';
				$lastNameCnt = 0;
			}

			$pid = $itemId;
			if (!isset($subTotals['fullName']) || $subTotals['fullName'] != $itemValue['fullName'])
			{
				//$this->insertSubTotals ($subTotals, $data);
				$subTotals = array ('fullName' => $itemValue['fullName'], 'pairIds' => array ($pid => 1));

				$lastNameCnt = 0;
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
			//$this->incSubTotals($subTotals, $itemValue, $curr, TRUE);
			//$this->incSubTotals($totals, $itemValue, $curr);
			$data[$itemId] = $itemValue;

			$lastName = $itemValue['fullName'];
		}

		if (isset ($itemId) && isset ($data[$itemId]) && $lastNameCnt === 0)
			$data[$itemId]['_options']['afterSeparator'] = 'separator';

		if ($lastName !== FALSE)
		{
			//$this->insertSubTotals ($subTotals, $data);
			//$this->insertSubTotals ($totals, $data, TRUE);
		}

		$this->totals = $totals;

		return $data;
	}

  public function createExport($data)
  {
    $this->exportText = '';

    // -- head / first row
    if ($this->exportType === self::etCeskaSporitelnaCSV)
      $this->createExport_head_ceskaSporitelna($data);

    // -- rows
    foreach ($data as $r)
    {
      if ($this->exportType === self::etCeskaSporitelnaCSV)
      {
        $this->createExport_row_ceskaSporitelna($r);
        continue;
      }

      $rowItems = [];
      $rowItems[] = '"'.Str::upToLen(str_replace('"', '""', $r['fullName']), 250).'"';
      if (isset($r['oid']) && $r['oid'] !== '')
        $rowItems[] = $r['oid'];
      elseif (isset($r['vatId']) && $r['vatId'] !== '')
        $rowItems[] = $r['vatId'];
      else
        $rowItems[] = '';

      $rowItems[] = $r['country'];
      $rowItems[] = '"'.Str::upToLen(str_replace('"', '""', $r['address']), 250).'"';

      $rowItems[] = Str::upToLen($r['s1'], 10);
      $rowItems[] = $r['curr'];

      $rowItems[] = sprintf("%.2F", $r['request']);
      $rowItems[] = sprintf("%.2F", $r['rest']);

      if (isset($r['dateIssue']) && !Utils::dateIsBlank($r['dateIssue']))
        $rowItems[] = $r['dateIssue']->format('Y-m-d');
      else
        $rowItems[] = '';

      if (isset($r['date']) && !Utils::dateIsBlank($r['date']))
        $rowItems[] = $r['date']->format('Y-m-d');
      else
        $rowItems[] = '';

      $this->exportText .= implode(',', $rowItems)."\r\n";
    }
  }

  protected function createExport_head_ceskaSporitelna($data)
  {
    $exportDef = $this->exportTypes[$this->exportTypeId];

    $headItems = [];
    $headItems[] = $this->endDate->format('d.m.Y');
    $headItems[] = Str::upToLen($exportDef['options']['pledgeAgreementNumber'] ?? '', 25);

    $pledgeAgreementDate = Utils::createDateTime($exportDef['options']['pledgeAgreementDate'] ?? Utils::today());
    $headItems[] = $pledgeAgreementDate->format('d.m.Y');
    $headItems[] = strval($exportDef['options']['pledgeAgreementAppendixNum'] ?? 0);

    $ownerPersonNdx = intval($this->app()->cfgItem ('options.core.ownerPerson', 0));
    $ownerPerson = $this->app()->loadItem($ownerPersonNdx, 'e10.persons.persons');
    if ($ownerPerson)
    {
      $headItems[] = '"'.Str::upToLen(str_replace('"', '""', $ownerPerson['fullName']), 120).'"';
      $ownerOid = $this->loadPersonOid($ownerPersonNdx);
      $headItems[] = $ownerOid;

      $this->loadPersonMainAddress($ownerPerson, $ownerPersonNdx);
      $headItems[] = '"'.Str::upToLen(str_replace('"', '""', $ownerPerson['address'] ?? ''), 120).'"';
    }
    else
    {
      $headItems[] = '';
      $headItems[] = '';
      $headItems[] = '';
    }

    $this->exportText .= implode(',', $headItems)."\r\n";
  }

  protected function createExport_row_ceskaSporitelna($r)
  {
    if (!$this->rowIsEnabled($r))
      return;

    $rowItems = [];
    $rowItems[] = '"'.Str::upToLen(str_replace('"', '""', $r['fullName']), 120).'"';
    if (isset($r['oid']) && $r['oid'] !== '')
      $rowItems[] = $r['oid'];
    elseif (isset($r['vatId']) && $r['vatId'] !== '')
      $rowItems[] = $r['vatId'];
    else
      $rowItems[] = '';

    $rowItems[] = $r['country'];
    $rowItems[] = '"'.Str::upToLen(str_replace('"', '""', $r['address']), 120).'"';

    $rowItems[] = Str::upToLen($r['s1'], 10);
    $rowItems[] = $r['curr'];

    $rowItems[] = sprintf("%.2F", $r['request']);
    $rowItems[] = sprintf("%.2F", $r['rest']);

    if (isset($r['dateIssue']) && !Utils::dateIsBlank($r['dateIssue']))
      $rowItems[] = $r['dateIssue']->format('d.m.Y');
    else
      $rowItems[] = '';

    if (isset($r['date']) && !Utils::dateIsBlank($r['date']))
      $rowItems[] = $r['date']->format('d.m.Y');
    else
      $rowItems[] = '';

    $this->exportText .= implode(',', $rowItems)."\r\n";
  }

  protected function rowIsEnabled($r)
  {
    if ($this->exportType === self::etCeskaSporitelnaCSV)
    {
      if ((!isset($r['oid']) || $r['oid'] == '') && (!isset($r['vatId']) || $r['vatId'] == ''))
        return FALSE;

      if (!isset($r['docType']) || $r['docType'] !== 'invno')
        return FALSE;
    }

    $exportDef = $this->exportTypes[$this->exportTypeId];

    $minAmount = intval($exportDef['options']['minAmount'] ?? 0);
    if ($minAmount && $r['restHc'] <= $minAmount)
       return FALSE;

    $maxTotalAmount = intval($exportDef['options']['maxTotalAmount'] ?? 0);

    if ($maxTotalAmount && ($this->totalAmount + $r['restHc']) > $maxTotalAmount)
       return FALSE;

    $this->totalAmount += $r['restHc'];
    return TRUE;
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

  public function subReportsList ()
	{
		$d[] = ['id' => 'items', 'icon' => 'detailBalanceByItems', 'title' => 'Položkově'];
		$d[] = ['id' => 'export', 'icon' => 'system/iconFile', 'title' => 'Export'];
		return $d;
	}

  protected function checkPerson (&$item)
  {
    $personNdx = $item['person'] ?? 0;
    if (!$personNdx)
    {

      return;
    }

    $oid = $this->loadPersonOid($personNdx);
    if ($oid !== '')
      $item['oid'] = $oid;

    $vatId = $this->loadPersonVatId($personNdx);
    if ($vatId !== '')
      $item['vatId'] = $vatId;

    $this->loadPersonMainAddress($item, $personNdx);
  }

	protected function loadPersonOid ($personNdx)
	{
		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'oid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			return $r['valueString'];
		}

    return '';
	}

	protected function loadPersonVatId ($personNdx)
	{
		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'taxid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			return $r['valueString'];
		}

    return '';
	}

	protected function loadPersonMainAddress(&$item, $personNdx)
	{
    $q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $personNdx);
    array_push ($q, ' AND [contacts].[docState] = %i', 4000);
    array_push ($q, ' AND [contacts].[flagAddress] = %i', 1);
    array_push ($q, ' AND [contacts].[flagMainAddress] = %i', 1);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
      $item['country'] = strtoupper(World::countryId($this->app(), $r['adrCountry']));

      $adrTitle = [];
      if ($r['adrStreet'] !== '')
        $adrTitle[] = $r['adrStreet'];
      if ($r['adrCity'] !== '')
        $adrTitle[] = $r['adrCity'];
      if ($r['adrZipCode'] !== '')
        $adrTitle[] = $r['adrZipCode'];

      if (count($adrTitle) !== 0)
      {
        $item['address'] = implode(', ', $adrTitle);
        return;
      }
		}
	}

	public function createToolbarSaveAs (&$printButton)
	{
    $exportDef = $this->exportTypes[$this->exportTypeId];

		$printButton['dropdownMenu'][] = [
			'text' => $exportDef['saveAsTitle'], 'icon' => 'system/actionDownload',
			'type' => 'reportaction', 'action' => 'print',
      'class' => 'e10-print', 'data-format' => $exportDef['saveAsFileType'],
			'data-filename' => $this->saveAsFileName($exportDef['saveAsFileType'])
		];

    parent::createToolbarSaveAs ($printButton);
	}

	public function saveAsFileName ($type)
	{
    parent::saveAsFileName($type);

		$fn = 'export-pohledavek';
		$fn .= '.'.$this->format;
		return $fn;
	}

	public function saveReportAs ()
	{
    $exportDef = $this->exportTypes[$this->exportTypeId];
		if ($this->format === $exportDef['saveAsFileType'])
    {
      $baseFileName = $this->saveAsFileName ($exportDef['saveAsFileType']);
      $this->fullFileName = __APP_DIR__.'/tmp/'.$baseFileName;
      $this->saveFileName = $this->saveAsFileName ($exportDef['saveAsFileType']);

			if ($this->exportType === self::etCeskaSporitelnaCSV)
			{
				$e = iconv('UTF-8', 'CP1250', $this->exportText);
				file_put_contents($this->fullFileName, $e);
				return;
			}

      file_put_contents($this->fullFileName, $this->exportText);
      return;
    }

    parent::saveReportAs ();
	}
}
