<?php

namespace e10doc\ddm\libs;
use \Shipard\Utils\Json;


/**
 * Class ImportJsonDDMEngine
 */
class ImportJsonDDMEngine extends \Shipard\Base\Utility
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

		if (!isset($data['id']) || !isset($data['items']))
		{
			$errorMsg .= 'Chyba - nejedná se o platnou konfiguraci';
			return 0;
		}

    $this->cfgData = $data;

    return 1;
  }

  public function import()
  {
    $ddmNdx = 0;
    $existHead = $this->db()->query('SELECT * FROM [e10doc_ddm_ddm] WHERE [formatId] = %s', $this->cfgData['id'])->fetch();
    if ($existHead)
    {
      $update = [
        'formatId' => $this->cfgData['id'],
        'fullName' => $this->cfgData['name'],
        'signatureString' => $this->cfgData['signatureString'],
      ];

      $this->db()->query('UPDATE [e10doc_ddm_ddm] SET ', $update, ' WHERE ndx = %i', $existHead['ndx']);
      $ddmNdx = $existHead['ndx'];

      $this->db()->query('DELETE FROM [e10doc_ddm_ddmItems] WHERE [ddm] = %i', $existHead['ndx']);
    }
    else
    {
      $insert = [
        'formatId' => $this->cfgData['id'],
        'fullName' => $this->cfgData['name'],
        'signatureString' => $this->cfgData['signatureString'],
        'docState' => 4000, 'docStateMain' => 2,
      ];
      $this->db()->query('INSERT INTO [e10doc_ddm_ddm] ', $insert);
      $ddmNdx = intval ($this->db()->getInsertId ());
    }

    if (!$ddmNdx)
      return;

    foreach ($this->cfgData['items'] as $itm)
    {
      $newItem = $itm;
      $newItem['ddm'] = $ddmNdx;
      $newItem['docState'] = 4000;
      $newItem['docStateMain'] = 2;

      $this->db()->query('INSERT INTO [e10doc_ddm_ddmItems] ', $newItem);
    }

    /** @var \e10doc\ddm\TableDDM */
		$tableDDM = $this->app()->table('e10doc.ddm.ddm');
		$recData = $tableDDM->loadItem($ddmNdx);
		$configuration = $tableDDM->createConfiguration($recData);
    $update = ['configuration' => Json::lint($configuration)];
    $this->db()->query('UPDATE [e10doc_ddm_ddm] SET ', $update, ' WHERE ndx = %i', $ddmNdx);
    $tableDDM->docsLog($ddmNdx);
  }
}
