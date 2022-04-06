<?php

namespace lib\nomenclature\country\cz;

use \lib\nomenclature\ImportNomenclature, \e10\str;


/**
 * Class ImportCZNUTS
 * @package lib\nomenclature\country\cz
 */
class ImportCZNUTS extends ImportNomenclature
{
	var $nomecTypeNdx = 0;

	public function run()
	{
		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nuts')->fetch();
		if ($nomencType)
			$this->nomecTypeNdx = $nomencType['ndx'];

		$this->importNUTS3();
		$this->importNUTS4();
	}

	protected function importNUTS3 ()
	{
		// https://apl.czso.cz/iSMS/cisdata.jsp?kodcis=108
		$url = 'https://apl.czso.cz/iSMS/cisexp.jsp?kodcis=108&typdat=0&cisvaz=80007&datpohl=06.04.2022&cisjaz=203&format=0';
		if (!$this->downloadFile($url, 'nomenc-cz-nuts3.xml'))
			return;

		$data = file_get_contents($this->destFileName);

		$simpleXml = simplexml_load_string($data);
		$json = json_decode (json_encode($simpleXml), TRUE);

		foreach ($json['DATA'] as $d)
		{
			foreach ($d as $item)
			{
				$newItem = [
						'nomencType' => $this->nomecTypeNdx,
						'id' => 'cz-nuts-'.$item['CHODNOTA'],
						'itemId' => $item['CHODNOTA'],
						'level' => 3,
						'order' => $item['CHODNOTA'],
						'shortName' => str::toDb($item['ZKRTEXT']),
						'fullName' => str::toDb($item['TEXT']),
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
			}
		}
	}

	protected function importNUTS4 ()
	{
		// https://apl.czso.cz/iSMS/cisdata.jsp?kodcis=118
		$url = 'https://apl.czso.cz/iSMS/cisexp.jsp?kodcis=118&typdat=0&cisvaz=108&datpohl=06.04.2022&cisjaz=203&format=0';
		if (!$this->downloadFile($url, 'nomenc-cz-nuts4.xml'))
			return;

		$data = file_get_contents($this->destFileName);

		$simpleXml = simplexml_load_string($data);
		$json = json_decode (json_encode($simpleXml), TRUE);

		foreach ($json['DATA'] as $d)
		{
			foreach ($d as $item)
			{
				$newItem = [
						'nomencType' => $this->nomecTypeNdx,
						'id' => 'cz-nuts-'.$item['CHODNOTA'],
						'itemId' => $item['CHODNOTA'],
						'level' => 4,
						'order' => $item['CHODNOTA'],
						'shortName' => str::toDb($item['ZKRTEXT']),
						'fullName' => str::toDb($item['TEXT']),
						'ownerItem' => 0,
						'docState' => 4000, 'docStateMain' => 2,
				];
				$newItem['validFrom'] = $item['ADMPLOD'];
				if ($item['ADMNEPO'] != '9999-09-09')
					$newItem['validTo'] = $item['ADMNEPO'];

				$ownerItem = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [nomencType] = %i', $this->nomecTypeNdx,
						'AND [id] = %s', substr($newItem['id'], 0, -1))->fetch();
				if ($ownerItem)
					$newItem['ownerItem'] = $ownerItem['ndx'];

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
			}
		}
	}
}

