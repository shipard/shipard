<?php

namespace e10doc\balance\libs;


/**
 * class ExchDiffsReport
 */
class ExchDiffsReport extends \e10doc\core\libs\reports\GlobalReport
{
  var $fiscalYear = 0;
  var $balance = 0;

  var $currencies;

  var $data;

  function init ()
	{
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

    $this->addParam ('fiscalYear');

    $this->addParam('switch', 'balance', ['title' => 'Saldo', 'switch' => ['2000' => 'Závazky', '1000' => 'Pohledávky'], 'radioBtn' => 1, 'defaultValue' => '1000']);

    parent::init();

    $this->fiscalYear = intval($this->reportParams ['fiscalYear']['value']);
    $this->balance = intval($this->reportParams ['balance']['value']);

    $balances = $this->app()->cfgItem ('e10.balance');
    $thisBalanceDef = $balances[$this->balance];

    $this->setInfo('title', 'Kurzové rozdíly / '.$thisBalanceDef['name']);
		$this->setInfo('icon', $thisBalanceDef['icon']);
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalYear']['activeTitle']);
  }

  public function loadData()
  {
		$q = [];
    array_push ($q, 'SELECT heads.docNumber, heads.docType, heads.paymentMethod, persons.fullName, saldo.*');
    //array_push ($q, ' saldo.symbol1 as symbol1, saldo.symbol2 as symbol2, ');
		//array_push ($q, ' saldo.currency as currency');
    array_push ($q, ' FROM e10doc_balance_journal AS saldo');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON saldo.person = persons.ndx');
		array_push ($q, '	LEFT JOIN e10doc_core_heads as heads ON saldo.docHead = heads.ndx');
		array_push ($q, ' WHERE saldo.[fiscalYear] = %i', $this->fiscalYear);

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

        $sumRow[$sumId]['debsAccountId'] = [
          'text' => '', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconFile',
          'title' => 'Vygenerovat účetní doklad',
          'class' => 'pull-right',
          'element' => 'span',
          'btnClass' => 'pull-right',
          'data-class' => 'e10doc.balance.libs.ExchDiffsWizard',
          'data-addparams' => 'focusedPK='.$r['person'].'&balance='.$this->balance.'&currency='.$r['currency'].'&fiscalYear='.$this->fiscalYear,
        ];
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
						's1' => $r['symbol1'], 's2' => $r['symbol2'], 's3' => $r['symbol3']
          ];
					$item['curr'] = $this->currencies[$r['currency']]['shortcut'];

					$data[$pid] = $item;
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

	function createContent ()
	{
		$this->loadData();

    $h = [
      '#' => '#', 'fullName' => 'Osoba',
      'docNumber' => 'Doklad', 's1' => ' VS', 's2' => ' SS', 'date' => 'Splatnost',
      'request' => ' Částka CM', 'curr' => 'Měna',
      'requestHc' =>  ' Předpis DM', 'paymentHc' => ' Uhrazeno DM', 'restHc' => ' Zbývá DM',
      'debsAccountId' => 'Účet'
    ];

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->data, 'main' => TRUE, 'params' => ['disableZeros' => 1]]);
  }
}

