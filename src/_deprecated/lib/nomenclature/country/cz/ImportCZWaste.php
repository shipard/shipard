<?php

namespace lib\nomenclature\country\cz;

use \lib\nomenclature\ImportNomenclature, \Shipard\Utils\Str, \Shipard\Utils\Utils;


/**
 * Class ImportCZNACE
 */
class ImportCZWaste extends ImportNomenclature
{
	var $nomecTypeNdx = 0;

  var $wasteCodes = [];

	public function run()
	{

		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-waste')->fetch();
		if ($nomencType)
			$this->nomecTypeNdx = $nomencType['ndx'];

		$this->import();
	}

	protected function import ()
	{
		$fn = __SHPD_MODULES_DIR__.'install/data/countries/cz/datasets/wastecodes.json';
    $data = Utils::loadCfgFile($fn);


		foreach ($data as $wasteCodeId => $wasteCode)
		{
      $this->importItem($wasteCodeId, $wasteCode);
		}
	}

	protected function importItem ($wasteCodeId, $wasteCode)
	{
		$ownerItemNdx = 0;
    $level = 1;
    $order = $wasteCodeId;

    if (isset($wasteCode['parent']))
    {
      $parentRecData = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [id] = %s', 'cz-waste-'.$wasteCode['parent'])->fetch();
      $level = $parentRecData['level'] + 1;
      $ownerItemNdx = $parentRecData['ndx'];
    }
    
    $newItem = [
				'nomencType' => $this->nomecTypeNdx,
				'id' => 'cz-waste-'.$wasteCodeId,
				'itemId' => $wasteCodeId,
				'level' => $level,
				'order' => $order,
				'shortName' => Str::toDb($wasteCode['name'], 240),
				'fullName' => Str::toDb($wasteCode['name'], 240),
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
}

