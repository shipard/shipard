<?php

namespace lib\nomenclature;

use \e10\Utility, \Shipard\Utils\Utils, \Shipard\Utils\Str;


/**
 * Class InstallNomenclature
 */
class InstallNomenclature extends Utility
{
  var string $nomencId = '';
  var ?array $nomencCfg = NULL;
  var int $nomencTypeNdx = 0;
  var ?array $data = NULL;

  function loadConfig()
  {
    $idParts = explode('-', $this->nomencId);
    $fnBase = $idParts[0].'/'.$this->nomencId;

    $fnFull = __SHPD_MODULES_DIR__.'/services/nomenc/config/'.$fnBase.'.json';
    $cfg = $this->loadCfgFile($fnFull);
    if ($cfg === FALSE)
    {
      
      return FALSE;
    }

    $this->nomencCfg = $cfg;

    return TRUE;
  }

  function checkNomencType()
  {
    $exist = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', $this->nomencCfg['typeId'])->fetch();
    if (isset($exist['id']))
    {
      $this->nomencTypeNdx = $exist['ndx'];
      return TRUE;
    }

    $insert = $this->nomencCfg['nomencTypeDef'];
    $insert['id'] = $this->nomencCfg['typeId'];
    $insert['docState'] = 4000;
    $insert['docStateMain'] = 2;

    $this->db()->query('INSERT INTO [e10_base_nomencTypes]', $insert);
    $this->nomencTypeNdx = intval ($this->db()->getInsertId ());
    if (!$this->nomencTypeNdx)
    {
      return $this->err('Insert new type failed...');
    }

    return TRUE;
  }

  function loadData()
  {
    $idParts = explode('-', $this->nomencId);
    $fnBase = $this->nomencId;

    $fnFull = __SHPD_MODULES_DIR__.'/install/data/countries/'.$idParts[0].'/datasets/nomenclature/'.$fnBase.'.json';
    $data = Utils::loadCfgFile($fnFull);
    if (!$data)
    {
      $this->err('File `'.$fnFull.'` not found');
      return FALSE;
    }

    $this->data = $data;

    return TRUE;
  }

  function doImport()
  {
    foreach ($this->data as $wasteCodeId => $wasteCode)
		{
      $this->importItem($wasteCodeId, $wasteCode);
		}

  }

	protected function importItem ($itemId, $item)
	{
		$ownerItemNdx = 0;
    $level = 1;
    $order = $item['order'];

    if (isset($item['parentId']))
    {
      $parentRecData = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [id] = %s', $this->nomencId.'-'.$item['parentId'])->fetch();
      $level = $parentRecData['level'] + 1;
      $ownerItemNdx = $parentRecData['ndx'];
    }
    
    $newItem = [
				'nomencType' => $this->nomencTypeNdx,
				'id' => $this->nomencId.'-'.$itemId,
				'itemId' => $itemId,
				'level' => $level,
				'order' => $order,
				'shortName' => Str::toDb($item['shortName'], 240),
				'fullName' => Str::toDb($item['fullName'], 240),
				'ownerItem' => $ownerItemNdx,
				'docState' => 4000, 'docStateMain' => 2,
		];

		$exist = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [nomencType] = %i', $newItem['nomencType'],
				'AND [id] = %s', $newItem['id'])->fetch();
		if ($exist)
		{
			$this->db()->query('UPDATE [e10_base_nomencItems] SET ', $newItem, 'WHERE [ndx] = %i', $exist['ndx']);
		}
		else
		{
			$this->db()->query('INSERT INTO [e10_base_nomencItems] ', $newItem);
		}
	}

  public function run()
  {
    if (!$this->loadConfig())
      return;

    if (!$this->checkNomencType())  
      return;
    if (!$this->loadData())
      return;

    $this->doImport();
  }
}

