<?php

namespace lib\nomenclature\country\cz;

use \lib\nomenclature\ImportNomenclature, \e10\str;


/**
 * Class ImportCZNACE
 * @package lib\nomenclature\country\cz
 */
class ImportCZNACE extends ImportNomenclature
{
	var $srcUrl = 'https://apl.czso.cz/iSMS/cisexp.jsp?kodcis=80004&typdat=0&cisvaz=5103&cisjaz=203&format=0';
	var $nomecTypeNdx = 0;

	public function run()
	{
		if (!$this->downloadFile($this->srcUrl, 'nomenc-cz-nace.xml'))
			return;

		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nace')->fetch();
		if ($nomencType)
			$this->nomecTypeNdx = $nomencType['ndx'];

		$this->import();
	}

	protected function import ()
	{
		$data = file_get_contents($this->destFileName);

		$simpleXml = simplexml_load_string($data);
		$json = json_decode (json_encode($simpleXml), TRUE);

		foreach ($json['DATA'] as $d)
		{
			foreach ($d as $item)
			{
				$this->importItem($item, NULL, 1, NULL);
			}
		}
	}

	protected function importItem ($item, $ownerItem, $level, $mainItem)
	{
		$ownerItemNdx = ($ownerItem) ? $ownerItem['ndx'] : 0;
		$order = ($mainItem) ? $mainItem['itemId'].'.'.$item['CHODNOTA'] : $item['CHODNOTA'];
		$newItem = [
				'nomencType' => $this->nomecTypeNdx,
				'id' => 'cz-nace-'.$item['CHODNOTA'],
				'itemId' => $item['CHODNOTA'],
				'level' => $level,
				'order' => $order,
				'shortName' => str::toDb($item['ZKRTEXT']),
				'fullName' => str::toDb($item['TEXT']),
				'ownerItem' => $ownerItemNdx,
				'docState' => 4000, 'docStateMain' => 2,
		];

		$newItem['validFrom'] = $item['ADMPLOD'];
		if ($item['ADMNEPO'] != '9999-09-09')
			$newItem['validTo'] = $item['ADMNEPO'];

		$exist = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [nomencType] = %i', $newItem['nomencType'],
				'AND [id] = %s', $newItem['id'])->fetch();
		if ($exist)
		{
			$newItemNdx = $exist['ndx'];
			$this->db()->query('UPDATE [e10_base_nomencItems] SET ', $newItem, 'WHERE [ndx] = %i', $exist['ndx']);
		}
		else
		{
			$this->db()->query('INSERT INTO [e10_base_nomencItems] ', $newItem);
			$newItemNdx = intval ($this->db()->getInsertId ());
		}

		$newItem['ndx'] = $newItemNdx;

		if (!isset ($item['POLOZKA']))
			return;

		$mi = ($mainItem) ? $mainItem : $newItem;

		if (isset($item['POLOZKA']['TEXT']))
		{
			$this->importItem($item['POLOZKA'], $newItem, $level + 1, $mi);
			return;
		}

		foreach ($item['POLOZKA'] as $iitem)
		{
			$this->importItem($iitem, $newItem, $level + 1, $mi);
		}
	}
}

