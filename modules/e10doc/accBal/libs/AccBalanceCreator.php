<?php

namespace e10doc\accBal\libs;

use \Shipard\base\Utility;
use \Shipard\Utils\Json;


/**
 * class AccBalanceCreator
 */
class AccBalanceCreator extends Utility
{
  var $documentNdx = 0;
  var $documentRecData = NULL;

  var ?\e10doc\accBal\libs\AccBalanceCfg $balacesCfg = NULL;

  var $balanceJournalItems = [
    0 => [], // requests
    1 => [], // payments
  ];


  public function setDocument($documentNdx)
  {
    $this->documentNdx = $documentNdx;
    $this->documentRecData = $this->app()->loadItem($this->documentNdx, 'e10doc.core.heads');
  }

  protected function loadBalancesCfg()
  {
    $this->balacesCfg = new \e10doc\accBal\libs\AccBalanceCfg($this->app());
    $this->balacesCfg->setDate($this->documentRecData['dateAccouting'] ?? NULL);
    $this->balacesCfg->run();
  }

  protected function createBalanceJournal()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [e10doc_debs_journal]');
    array_push($q, ' WHERE [document] = %i', $this->documentNdx);
    array_push($q, ' ORDER BY [accRing], [ndx]');

    $rows = $this->app()->db()->query($q);
    foreach ($rows as $row)
    {
      $this->createBalanceJournalItem($row->toArray());
    }
  }

  protected function createBalanceJournalItem($accJournalRow)
  {
    //error_log("!!!: ".json_encode($accJournalRow));

    foreach ($this->balacesCfg->accounts as $accBalCfg)
    {
      //error_log("##TST1: `{$accJournalRow['accountId']}` / `{$accBalCfg['accountId']}`");
      if (!str_starts_with($accJournalRow['accountId'], $accBalCfg['accountId']))
        continue;
      //error_log("##TST2:");
      if ($accBalCfg['accSide'] !== $accJournalRow['side'])
        continue;

      if ($accBalCfg['amountsSign'] === 1 && $accJournalRow['money'] < 0) // only positive amounts: > 0
        continue;
      if ($accBalCfg['amountsSign'] === 2 && $accJournalRow['money'] > 0) // only negative amounts: < 0
        continue;

      $balanceJournalItem = $this->balanceJournalItem($accJournalRow, $accBalCfg);
      $this->balanceJournalItems[$accBalCfg['balSide']][] = $balanceJournalItem;

        /*
        		{"id": "accAmountsSign", "name": "Částky", "type": "enumInt",
			        "enumValues": {"0": "Všechny", "1": "Kladné", "2": "Záporné"}},
        */
    }

    //print_r($this->balanceJournalItems);

    //echo "\n\n".Json::lint($this->balanceJournalItems)."\n\n";
  }

  protected function balanceJournalItem($accJournalRow, $accBalCfg)
  {
    $amount = $accJournalRow['money'];
    if ($accBalCfg['balModifySign'] === 1)
      $amount = -$amount;
    $amountHc = $accJournalRow['money'];
    if ($accBalCfg['balModifySign'] === 1)
      $amountHc = -$amountHc;

    $item = [
      'balance' => $accBalCfg['balance'],
      'balSide' => $accBalCfg['balSide'],
      'fiscalYear' => $accJournalRow['fiscalYear'],
      'person' => $accJournalRow['person'],
      'symbol1' => $accJournalRow['symbol1'],
      'symbol2' => $accJournalRow['symbol2'],
      'symbol3' => $accJournalRow['symbol3'],
      'doc' => $accJournalRow['document'],
      'accountId' => $accJournalRow['accountId'],
      'amount' => $amount,
      'amountHc' => $amountHc,
    ];

    if ($accBalCfg['balSide'] === 0)
    { // request
      $item['docRequest'] = $accJournalRow['document'];
      $item['request'] = $amount;
      $item['requestHc'] = $amountHc;
      $item['resAmountHc'] = $amountHc;
      //$item['requestQ'] = $accJournalRow['quantity'];
    }
    else
    { // payment
      $item['docPayment'] = $accJournalRow['document'];
      $item['payment'] = $amount;
      $item['paymentHc'] = $amountHc;
      //$item['paymentQ'] = $accJournalRow['quantity'];
    }

    return $item;

    /*
    {"id": "person", "name": "Osoba", "type": "int", "reference": "e10.persons.persons"},
		{"id": "symbol1", "name": "Variabilní symbol", "label": "VS", "type": "string", "len": 20},
		{"id": "symbol2", "name": "Specifický symbol", "label": "SS", "type": "string", "len": 20},
		{"id": "symbol3", "name": "Konstantní symbol", "label": "KS", "type": "string", "len": 10},

    ------

		{"id": "balSide", "name": "Strana", "type": "enumInt",
			"enumValues": {"0": "Předpis", "1": "Úhrada"}},
		{"id": "balance", "name": "Saldokonto", "type": "int"},
		{"id": "fiscalYear", "name": "Rok", "type": "int", "reference": "e10doc.base.fiscalyears"},
		{"id": "person", "name": "Osoba", "type": "int"},
		{"id": "symbol1", "name": "Variabilní symbol", "type": "string", "len": 20},
		{"id": "symbol2", "name": "Specifický symbol", "type": "string", "len": 20},
		{"id": "symbol3", "name": "Konstantní symbol", "type": "string", "len": 10},

    {"id": "doc", "name": "Doklad", "type": "int", "reference": "e10doc.core.heads"},
    {"id": "docRequest", "name": "Doklad předpisu", "type": "int", "reference": "e10doc.core.heads"},
    {"id": "docPayment", "name": "Doklad úhrady", "type": "int", "reference": "e10doc.core.heads"},
    {"id": "journalRequest", "name": "Řádek předpisu", "type": "int", "reference": "e10doc.accBal.journal"},

		{"id": "currency", "name": "Měna", "type": "int", "reference": "e10.world.currencies"},
		{"id": "homeCurrency", "name": "Měna domácí", "type": "int", "reference": "e10.world.currencies"},

		{"id": "amount", "name": "Částka", "type": "money"},
		{"id": "amountHc", "name": "Částka v domácí měně", "type": "money"},

		{"id": "quantity", "name": "Množství", "type": "number", "dec": 3},
		{"id": "unit", "name": "Jednotka", "type": "enumString", "len": 8,
			"enumCfg": {"cfgItem": "e10.witems.units", "cfgValue": "", "cfgText": "shortcut"}},

		{"id": "request", "name": "Předpis", "type": "money"},
		{"id": "requestHc", "name": "Předpis v domácí měně", "type": "money"},
		{"id": "requestQ", "name": "Předpis množství", "type": "number", "dec": 3},

		{"id": "payment", "name": "Úhrada", "type": "money"},
		{"id": "paymentHc", "name": "Úhrada v domácí měně", "type": "money"},
		{"id": "paymentQ", "name": "Úhrada množství", "type": "number", "dec": 3},

		{"id": "bankAccount", "name": "Bankovní účet", "label": "Č. účtu", "type": "string", "len": 40},

		{"id": "dateDue", "name": "Datum splatnosti", "type": "date"},

		{"id": "accountId", "name": "Účet", "type": "string", "len": 12}
    */
  }

  protected function saveBalanceJournal()
  {
    $this->saveBalanceJournalRequests();
    $this->saveBalanceJournalPayments();
  }

  protected function saveBalanceJournalRequests()
  {
    $usedRequests = [];
    foreach ($this->balanceJournalItems[0] as $balanceJournalItem)
    {
      $requestNdx = 0;
      $exist = $this->db()->query('SELECT * FROM [e10doc_accBal_journal] ',
                                  ' WHERE [doc] = %i', $balanceJournalItem['doc'],
                                  ' AND [fiscalYear] = %i', $balanceJournalItem['fiscalYear'],
                                  ' AND [balance] = %i', $balanceJournalItem['balance'],
                                  ' AND [symbol1] = %s', $balanceJournalItem['symbol1'],
                                  ' AND [symbol2] = %s', $balanceJournalItem['symbol2'],
                                  ' AND [person] = %i', $balanceJournalItem['person'],
                                 )->fetch();
      if ($exist)
      {
        $requestNdx = $exist['ndx'];
        $this->db()->query('UPDATE e10doc_accBal_journal SET ', $balanceJournalItem, ' WHERE [ndx] = %i', $exist['ndx']);
        //$this->recalcRequest($exist);
      }
      else
      {
        $this->db()->query('INSERT INTO [e10doc_accBal_journal] ', $balanceJournalItem);
        $requestNdx = $this->db()->getInsertId();
      }
      $balanceJournalItem['ndx'] = $requestNdx;
      //$this->recalcRequest($balanceJournalItem);
      $usedRequests[] = $requestNdx;
    }

    if (count($usedRequests))
    {
      $unusedRequests = $this->db()->query('SELECT * FROM [e10doc_accBal_journal] ',
                        ' WHERE [balSide] = %i', 0, // payment
                        ' AND [doc] = %i', $this->documentRecData['ndx'],
                        ' AND [ndx] NOT IN %in', $usedRequests,
      );

      foreach ($unusedRequests as $unusedRequest)
      {
        $this->db()->delete('e10doc_accBal_journal', 'ndx', $unusedRequest['ndx']);
      }
    }
  }

  protected function saveBalanceJournalPayments()
  {
    $this->db()->query('DELETE FROM [e10doc_accBal_journal] ',
                       ' WHERE [balSide] = %i', 1, // payment
                       ' AND [doc] = %i', $this->documentRecData['ndx'],
    );

    foreach ($this->balanceJournalItems[1] as $balanceJournalItem)
    {
      $requestItem = $this->db()->query('SELECT * FROM [e10doc_accBal_journal] ',
                                        ' WHERE [balSide] = %i', 0, // request
                                        ' AND [fiscalYear] = %i', $balanceJournalItem['fiscalYear'],
                                        ' AND [balance] = %i', $balanceJournalItem['balance'],
                                        ' AND [symbol1] = %s', $balanceJournalItem['symbol1'],
                                        ' AND [symbol2] = %s', $balanceJournalItem['symbol2'],
                                        ' AND [person] = %i', $balanceJournalItem['person'],
                                      )->fetch();

      if ($requestItem)
      {
        $balanceJournalItem['journalRequest'] = $requestItem['ndx'];
        $balanceJournalItem['docRequest'] = $requestItem['doc'];
      }

      $this->db()->query('INSERT INTO e10doc_accBal_journal ', $balanceJournalItem);

      if ($requestItem)
      {
        $this->recalcRequest($requestItem);
      }
    }
  }

  protected function recalcRequest($requestItem)
  {
    $q = [];
    array_push($q, 'UPDATE [e10doc_accBal_journal] SET ');
    array_push($q, ' [paymentHc] = (SELECT SUM([paymentHc]) FROM [e10doc_accBal_journal] ',
                   ' WHERE [balSide] = 1 AND [journalRequest] = %i),', $requestItem['ndx']);
    array_push($q, ' [resAmountHc] = [requestHc] - (SELECT SUM([paymentHc]) FROM [e10doc_accBal_journal] ',
                   ' WHERE [balSide] = 1 AND [journalRequest] = %i)', $requestItem['ndx']);

    array_push($q, ' WHERE [ndx] = %i', $requestItem['ndx']);

    $this->db()->query($q);
  }

  public function run()
  {
    $this->loadBalancesCfg();
    $this->createBalanceJournal();
    $this->saveBalanceJournal();
  }
}
