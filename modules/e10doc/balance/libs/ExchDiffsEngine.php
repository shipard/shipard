<?php

namespace e10doc\balance\libs;
use \Shipard\Utils\Utils;



/**
 * class ExchDiffsEngine
 */
class ExchDiffsEngine extends \Shipard\Base\Utility
{
	var $fiscalYear;
	var $fiscalYearCfg;
  var $personNdx = 0;
  var $balance = 0;
  var $currency = 0;

  var $newDocNdx = 0;
  var $data = [];
  var $lastDate = NULL;

  var $currencies;

	/** @var \e10doc\core\TableHeads */
	var $tableDocs;
	/** @var \e10doc\core\TableRows */
	var $tableRows;


  public function loadData()
  {
    $this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$q = [];
    array_push ($q, 'SELECT heads.docNumber, heads.docType, heads.paymentMethod, persons.fullName, saldo.*');
    array_push ($q, ' FROM e10doc_balance_journal AS saldo');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON saldo.person = persons.ndx');
		array_push ($q, '	LEFT JOIN e10doc_core_heads as heads ON saldo.docHead = heads.ndx');
		array_push ($q, ' WHERE saldo.[fiscalYear] = %i', $this->fiscalYear);

    array_push ($q, ' AND saldo.[currency] = %s', $this->currency);
    array_push ($q, ' AND saldo.[person] = %s', $this->personNdx);

		array_push ($q, ' AND EXISTS (');
		array_push ($q, ' SELECT pairId, sum(amountHc) as amount, ',
										' sum(requestHc) as requestHc, sum(paymentHc) as paymentHc,');
		array_push ($q, ' sum(request) as request, sum(payment) as payment');
    array_push ($q, ' FROM e10doc_balance_journal as j',
      ' WHERE j.[type] = %i', $this->balance, ' AND j.pairId = saldo.pairId AND j.[fiscalYear] = %i ', $this->fiscalYear);
    array_push ($q, ' AND j.[currency] != %s', 'czk');
		array_push ($q, ' GROUP BY j.pairId');
		array_push ($q, ' HAVING ');
    array_push ($q, '[request] = [payment] AND [requestHc] != [paymentHc]');
		array_push ($q, ')');

		array_push ($q, ' ORDER BY persons.[fullName], [saldo].[person], [side], saldo.[date], pairId');

    $rows = $this->app->db()->query ($q);
		$data = [];
    $lastPersonNdx = -1;
    $sumRow = [];

		forEach ($rows as $r)
		{
			$pid = $r['pairId'];
      $sumId = 'C'.$r['currency'];


      if ($lastPersonNdx !== $r['person'])
      {
        if ($lastPersonNdx !== -1)
        {
          $scnt = 0;
          foreach ($sumRow as $sumRowId => $sr)
          {
            $scnt++;
            $sr['restHc'] = $sr['requestHc'] - $sr['paymentHc'];
            if ($scnt === count($sumRow))
              $sr['_options']['afterSeparator'] = 'separator';
            $data['SR_'.$lastPersonNdx.'_'.$sumRowId] = $sr;
          }
          $sumRow = [];
        }
      }

      if (!isset($sumRow[$sumId]))
      {
        $sumRow[$sumId] = [
          'fullName' => $r['fullName'],
          'request' => 0.0,
          'payment' => 0.0,
          'requestHc' => 0.0,
          'paymentHc' => 0.0,
          'requestQ' => 0.0,
          'paymentQ' => 0.0,
          'rest' => 0.0,
          'restHc' => 0.0,
          'restQ' => 0.0,
        ];
        $sumRow[$sumId]['curr'] = $this->currencies[$r['currency']]['shortcut'];
        $sumRow[$sumId]['_options']['class'] = 'subtotal';
        $sumRow[$sumId]['_options']['colSpan'] = ['fullName' => 5];
      }

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
						'docNumber' => array ('text'=> $r['docNumber'], 'icon' => /*$this->docIcon($r)*/'', 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead']),
						'person' => $r['person'], 'fullName' => $r['fullName'],
						'date' => $r['date'],
						'request' => $r['request'], 'payment' => $r['payment'],
						'requestHc' => $r['requestHc'], 'paymentHc' => $r['paymentHc'],
						'requestQ' => $r['requestQ'], 'paymentQ' => $r['paymentQ'],
						'debsAccountId' => $r['debsAccountId'],
						's1' => $r['symbol1'], 's2' => $r['symbol2'], 's3' => $r['symbol3'],
            'currencyId' => $r['currency'],
          ];
					$item['curr'] = $this->currencies[$r['currency']]['shortcut'];

					$data[$pid] = $item;

          $this->lastDate = $r['date'];
				}

        $sumRow[$sumId]['request'] += $r['request'];
        $sumRow[$sumId]['requestHc'] += $r['requestHc'];
        $sumRow[$sumId]['requestQ'] += $r['requestQ'];
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
						'docNumber' => array ('text'=> $r['docNumber'], 'icon' => /*$this->docIcon($r)*/'', 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead']),
						'person' => $r['person'], 'fullName' => $r['fullName'],
						'date' => $r['date'],
						'request' => $r['request'], 'payment' => $r['payment'],
						'requestHc' => $r['requestHc'], 'paymentHc' => $r['paymentHc'],
						'requestQ' => $r['requestQ'], 'paymentQ' => $r['paymentQ'],
						's1' => $r['symbol1'], 's2' => $r['symbol2'], 's3' => $r['symbol3']
            ];
					$item['curr'] = $this->currencies[$r['currency']]['shortcut'];
					$data[$pid] = $item;
				}

        $sumRow[$sumId]['payment'] += $r['payment'];
        $sumRow[$sumId]['paymentHc'] += $r['paymentHc'];
        $sumRow[$sumId]['paymentQ'] += $r['paymentQ'];
			}
			$data[$pid]['rest'] = $data[$pid]['request'] - $data[$pid]['payment'];
			$data[$pid]['restHc'] = $data[$pid]['requestHc'] - $data[$pid]['paymentHc'];
			$data[$pid]['restQ'] = $data[$pid]['requestQ'] - $data[$pid]['paymentQ'];

      $lastPersonNdx = $r['person'];
    }

    if ($lastPersonNdx !== -1)
    {
      $scnt = 0;
      foreach ($sumRow as $sumRowId => $sr)
      {
        $scnt++;
        $sr['restHc'] = $sr['requestHc'] - $sr['paymentHc'];
        if ($scnt === count($sumRow))
          $sr['_options']['afterSeparator'] = 'separator';
        $data['SR_'.$lastPersonNdx.'_'.$sumRowId] = $sr;
      }
    }

    $this->data = $data;
  }

  protected function createDocument ()
	{
		$docHead = $this->createDocHead ();
		if ($docHead === FALSE)
			return;

    $docRows = $this->createDocsRows();

    $this->save ($docHead, $docRows);
  }

	function createDocHead ()
	{
		$dbCounter = $this->dbCounter();
		if (!$dbCounter)
		{
			return NULL;
		}

    $title = 'Kurzové rozdíly - '.(($this->balance == 2000 /* ZAV */) ? 'Závazky' /* ZAV */ : 'Pohledávky' /* POH */);
		$docDate = $this->lastDate;

		// docKind
		$docKinds = $this->app->cfgItem ('e10.docs.kinds', FALSE);
    $dk = utils::searchArray($docKinds, 'activity', 'balExchRateDiff');


    $docH = [];
    $docH ['docType'] = 'cmnbkp';
		$docH ['dbCounter']					= $dbCounter;

    $this->tableDocs->checkNewRec ($docH);

		$docH ['dateAccounting']		= $docDate;
		$docH ['dateIssue']					= $docDate;
		$docH ['person'] 						= $this->personNdx;
		$docH ['title'] 						= $title;
		$docH ['taxCalc']						= 0;
		$docH ['currency']					= Utils::homeCurrency($this->app, $docDate);
		$docH ['initState']					= 0;
    $docH ['docKind']						= $dk['ndx'] ?? 0;

		return $docH;
	}

  protected function createDocsRows()
  {
    $newRows = [];
    array_pop($this->data);

    $sumDebit = 0.0;
    $sumCredit = 0.0;

    $operation = ($this->balance == 2000 /* ZAV */) ? 1090012 /* ZAV */ : 1090011 /* POH */;

    foreach ($this->data as $r)
    {
			$newRow = [];
      $newRow ['operation'] = $operation;

      if ($this->balance == 2000)
      { // závazky
        if ($r['restHc'] > 0.0)
        {
          $newRow ['debit'] = $r['restHc'];
          $sumDebit += $newRow ['debit'];
        }
        else
        {
          $newRow ['credit'] = - $r['restHc'];
          $sumCredit += $newRow ['credit'];
        }
      }
      else
      { // pohledávky
        if ($r['restHc'] < 0.0)
        {
          $newRow ['debit'] = - $r['restHc'];
          $sumDebit += $newRow ['debit'];
        }
        else
        {
          $newRow ['credit'] = $r['restHc'];
          $sumCredit += $newRow ['credit'];
        }
      }
      $newRow ['symbol1'] = $r['s1'] ?? '';
      $newRow ['symbol2'] = $r['s2'] ?? '';
      $newRow ['currency'] = $r['currencyId'] ?? '';
      $newRow ['quantity'] = 1;
			$newRow ['priceItem'] = $r['restHc'];
      $newRow ['dateDue'] = $r['date'];
      $newRow ['person'] = $this->personNdx;

      $newRows[] = $newRow;
    }

    if ($sumDebit > 0.0)
    {
			$newRow = [];
      $newRow ['operation'] = 1099998;
      $newRow ['item'] = $this->searchAccItemFromMask('663');
      $newRow ['text'] = 'Kurzové zisky';

      $newRow ['credit'] = $sumDebit;

      $newRows[] = $newRow;
    }

    if ($sumCredit > 0.0)
    {
			$newRow = [];
      $newRow ['operation'] = 1099998;
      $newRow ['item'] = $this->searchAccItemFromMask('563');
      $newRow ['text'] = 'Kurzové ztráty';

      $newRow ['debit'] = $sumCredit;

      $newRows[] = $newRow;
    }

    return $newRows;
  }

	protected function save ($head, $rows)
	{
		if (!isset ($head['ndx']))
		{
			$docNdx = $this->tableDocs->dbInsertRec ($head);
		}
		else
		{
			$docNdx = $head['ndx'];
			$this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
			$this->tableDocs->dbUpdateRec ($head);
		}

		$f = $this->tableDocs->getTableForm ('edit', $docNdx);
		if ($f->checkAfterSave())
			$this->tableDocs->dbUpdateRec ($f->recData);

		forEach ($rows as $row)
		{
			$row['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($row, $f->recData);
		}

		$f->checkAfterSave();

    $this->tableDocs->dbUpdateRec ($f->recData);
		$this->tableDocs->checkAfterSave2 ($f->recData);
		$this->tableDocs->docsLog($f->recData['ndx']);

    $this->newDocNdx = $f->recData['ndx'];
	}

	function setPerson ($personNdx, $balance, $currency, $fiscalYear)
	{
    $this->personNdx = $personNdx;
    $this->balance = intval($balance);
    $this->currency = $currency;
		$this->fiscalYear = intval($fiscalYear);
		$this->fiscalYearCfg = $this->app->cfgItem ('e10doc.acc.periods.'.$fiscalYear);
	}

	protected function searchAccItemFromMask ($accountMask)
	{
		$row = $this->db()->query ('SELECT * FROM [e10_witems_items] WHERE [itemKind] = %i', 2,
                                ' AND [debsAccountId] LIKE %s', $accountMask.'%',
                                ' AND [docState] = %i', 4000,
                                ' ORDER by debsAccountId, ndx')->fetch();
		if ($row)
			return $row['ndx'];

		return 0;
	}


	protected function dbCounter()
	{
    $dbCounter = $this->db()->query('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s', 'cmnbkp',
      ' AND [activitiesGroup] = %s', 'bal', ' AND docState != %i', 9800)->fetch();

		if (!isset ($dbCounter['ndx']))
			return 0;

		return $dbCounter['ndx'];
	}

  public function run()
  {
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);

    $this->loadData();
    $this->createDocument();
  }
}