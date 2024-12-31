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

		if (!isset($data['globalId']) || !isset($data['accounts']))
		{
			$errorMsg .= 'Chyba - nejedná se o platnou konfiguraci';
			return 0;
		}

    $this->cfgData = $data;

    return 1;
  }

  public function import()
  {
    $balanceNdx = 0;
    $existHead = $this->db()->query('SELECT * FROM [e10doc_accBal_balances] WHERE [globalId] = %s', $this->cfgData['globalId'])->fetch();
    if ($existHead)
    {
      $update = [
        'globalId' => $this->cfgData['globalId'],
        'fullName' => $this->cfgData['fullName'],
        'shortName' => $this->cfgData['shortName'],
      ];

      $this->db()->query('UPDATE [e10doc_accBal_balances] SET ', $update, ' WHERE ndx = %i', $existHead['ndx']);
      $balanceNdx = $existHead['ndx'];

      $this->db()->query('DELETE FROM [e10doc_accBal_balancesAccounts] WHERE [balance] = %i', $existHead['ndx']);
    }
    else
    {
      $insert = [
        'globalId' => $this->cfgData['globalId'],
        'fullName' => $this->cfgData['fullName'],
        'shortName' => $this->cfgData['shortName'],
        'docState' => 4000, 'docStateMain' => 2,
      ];
      $this->db()->query('INSERT INTO [e10doc_accBal_balances] ', $insert);
      $balanceNdx = intval ($this->db()->getInsertId ());
    }

    if (!$balanceNdx)
      return;

    foreach ($this->cfgData['accounts'] as $itm)
    {
      $newItem = $itm;
      $newItem['balance'] = $balanceNdx;
      $newItem['docState'] = 4000;
      $newItem['docStateMain'] = 2;

      $this->db()->query('INSERT INTO [e10doc_accBal_balancesAccounts] ', $newItem);
    }

    /** @var \e10doc\ddm\TableDDM */
    /*
		$tableDDM = $this->app()->table('e10doc.ddm.ddm');
		$recData = $tableDDM->loadItem($ddmNdx);
		$configuration = $tableDDM->createConfiguration($recData);
    $update = ['configuration' => Json::lint($configuration)];
    $this->db()->query('UPDATE [e10doc_ddm_ddm] SET ', $update, ' WHERE ndx = %i', $ddmNdx);
    $tableDDM->docsLog($ddmNdx);
    */
  }
}
