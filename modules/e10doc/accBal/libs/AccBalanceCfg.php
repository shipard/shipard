<?php

namespace e10doc\accBal\libs;

use \Shipard\base\Utility;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;


/**
 * class AccBalanceCfg
 */
class AccBalanceCfg extends Utility
{
  var $forDate = NULL;

  var $accounts = [];
  var $balances = [];
  var $balancesCfg = [];


  public function setDate($date)
  {
    $this->forDate = Utils::createDateTime($date);
  }

  public function loadBalances()
  {
    $q = [];
    array_push ($q, 'SELECT [balances].* ');
		array_push ($q, ' FROM [e10doc_accBal_balances] AS [balances]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' ORDER BY [order], [fullName], [ndx]');
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'ndx' => $r['ndx'],
        'fullName' => $r['fullName'],
        'shortName' => $r['shortName'],
      ];
      $this->balances[$r['ndx']] = $item;
    }
  }

  public function exportBalances(array $balances)
  {
    $now = new \DateTime();

    $this->balancesCfg = [];
    $this->loadBalancesCfg($balances);

    $impExpCfg = [
      'title' => $this->app->cfgItem('dsi.name'),
      'exportDateTime' => $now->format('Y-m-d H:i:s'),
      'ver' => sha1(json_encode(array_values($this->balancesCfg))),
      'balances' => array_values($this->balancesCfg),
    ];
    $cfgString = Json::lint($impExpCfg);

    $baseFN = Utils::safeChars('saldokonta-'.str_replace('.', '', $this->app->cfgItem ('dsi.name', '')));
    $ffn = 'tmp/'.$baseFN . '-json.txt';
    $url = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid') . '/'. 'tmp/'.$baseFN . '-json.txt';

    file_put_contents($ffn, $cfgString);

    $info = [
      'url' => $url,
      'baseName' => $baseFN,
    ];
    return $info;
  }

  public function loadBalancesCfg(int|array $balances = 0)
  {
    $q = [];
    array_push ($q, 'SELECT [balances].* ');
		array_push ($q, ' FROM [e10doc_accBal_balances] AS [balances]');
		array_push ($q, ' WHERE 1');

    if (is_array($balances))
    {
      array_push ($q, ' AND [balances].[ndx] IN %in', $balances);
    }
    elseif ($balances !== 0)
    {
      array_push ($q, ' AND [balances].[ndx] = %i', $balances);
    }
    else
    {
      array_push($q, ' AND [balances].[docState] IN %in', [4000, 8000]);
    }

    array_push ($q, ' ORDER BY [order], [fullName], [ndx]');
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'fullName' => $r['fullName'],
        'shortName' => $r['shortName'],
        'globalId' => $r['globalId'],
        'order' => $r['order'],
      ];
      if (!Utils::dateIsBlank($r['validFrom']))
        $item['validFrom'] = $r['validFrom']->format('Y-m-d');
      if (!Utils::dateIsBlank($r['validTo']))
        $item['validTo'] = $r['validTo']->format('Y-m-d');

      $this->balancesCfg[$r['ndx']] = $item;

      $q = [];
      array_push($q, 'SELECT [ba].*');
      array_push($q, ' FROM [e10doc_accBal_balancesAccounts] AS [ba]');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [ba].[docState] IN %in', [4000, 8000]);
      array_push($q, ' AND [ba].[balance] = %i', $r['ndx']);
      array_push($q, ' ORDER BY [ba].[systemOrder], [ba].[accountId], [ba].[ndx]');
      $accRows = $this->db()->query($q);
      foreach ($accRows as $acc)
      {
        $accItem = [
          'accountId' => $acc['accountId'],
          'accSide' => $acc['accSide'],
          'balSide' => $acc['balSide'],
          'balModifySign' => $acc['balModifySign'],
          'accAmountsSign' => $acc['accAmountsSign'],
        ];
        $this->balancesCfg[$r['ndx']]['accounts'][] = $accItem;
      }
    }
  }

  protected function loadAccounts()
  {
    $q = [];
    array_push($q, 'SELECT [ba].*');
		array_push($q, ' FROM [e10doc_accBal_balancesAccounts] AS [ba]');
		array_push($q, ' WHERE 1');
    array_push($q, ' AND [ba].[docState] IN %in', [4000, 8000, 9000]);
		array_push($q, ' AND ([ba].[validFrom] IS NULL OR [ba].[validFrom] <= %d)', $this->forDate);
		array_push($q, ' AND ([ba].[validTo] IS NULL OR [ba].[validTo] >= %d)', $this->forDate);
    array_push($q, ' ORDER BY [ba].[systemOrder], [ba].[accountId], [ba].[ndx]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'accountId' => $r['accountId'],
        'balance' => $r['balance'],
        'accSide' => $r['accSide'],
        'balSide' => $r['balSide'],
        'balModifySign' => $r['balModifySign'],
        'amountsSign' => $r['accAmountsSign'],
      ];
      $this->accounts[] = $item;
    }
  }

  public function run()
  {
    $this->loadAccounts();
  }
}