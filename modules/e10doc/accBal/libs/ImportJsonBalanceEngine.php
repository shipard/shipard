<?php

namespace e10doc\accBal\libs;
use \Shipard\Utils\Json;


/**
 * Class ImportJsonBalanceEngine
 */
class ImportJsonBalanceEngine extends \Shipard\Base\Utility
{
  var $cfgText = '';
  var $cfgData = NULL;

  public function setCfgText($cfgText, &$errorMsg)
  {
    $this->cfgText = $cfgText;

		if (trim($cfgText) === '')
		{
			$errorMsg .= 'Chyba - nebyl zadán žádný konfigurační text';
			return 0;
		}

		$data = Json::decode($cfgText);
		if (!$data)
		{
			$errorMsg .= 'Chyba - neplatný obsah - konfigurační text obsahuje syntaktickou chybu';
			return 0;
		}

		if (!isset($data['balances']) || !isset($data['balances'][0]['globalId']) || !isset($data['balances'][0]['accounts']))
		{
			$errorMsg .= 'Chyba - nejedná se o platnou konfiguraci';
			return 0;
		}

    $this->cfgData = $data;

    return 1;
  }

  public function import()
  {
    /** @var \e10doc\accBal\TableBalancesAccounts */
    $tableBalancesAccounts = $this->app()->table('e10doc.accBal.balancesAccounts');

    foreach ($this->cfgData['balances'] as $b)
    {
      $balanceNdx = 0;
      $existHead = $this->db()->query('SELECT * FROM [e10doc_accBal_balances] WHERE [globalId] = %s', $b['globalId'])->fetch();
      if ($existHead)
      {
        $update = [
          'globalId' => $b['globalId'],
          'fullName' => $b['fullName'],
          'shortName' => $b['shortName'],
          'order' => $b['order'],
        ];

        $this->db()->query('UPDATE [e10doc_accBal_balances] SET ', $update, ' WHERE ndx = %i', $existHead['ndx']);
        $balanceNdx = $existHead['ndx'];

        $this->db()->query('DELETE FROM [e10doc_accBal_balancesAccounts] WHERE [balance] = %i', $existHead['ndx']);
      }
      else
      {
        $insert = [
          'globalId' => $b['globalId'],
          'fullName' => $b['fullName'],
          'shortName' => $b['shortName'],
          'order' => $b['order'],
          'docState' => 4000, 'docStateMain' => 2,
        ];
        $this->db()->query('INSERT INTO [e10doc_accBal_balances] ', $insert);
        $balanceNdx = intval ($this->db()->getInsertId ());
      }

      if (!$balanceNdx)
        return;

      foreach ($b['accounts'] as $itm)
      {
        $newItem = $itm;
        $newItem['balance'] = $balanceNdx;
        $newItem['docState'] = 4000;
        $newItem['docStateMain'] = 2;

        $tableBalancesAccounts->dbInsertRec($newItem);
      }
    }
  }
}
