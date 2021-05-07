<?php

namespace lib\nomenclature\country\cz;

use \lib\nomenclature\ImportNomenclature, \e10\str;


/**
 * Class ImportCZTOBE
 * @package lib\nomenclature\country\cz
 */
class ImportCZTOBE extends ImportNomenclature
{
	var $nomecTypeNdx = 0;

	public function run()
	{
		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-tobe')->fetch();
		if ($nomencType)
			$this->nomecTypeNdx = $nomencType['ndx'];

		$this->import();
	}

	protected function import()
	{
		// http://apl.czso.cz/iSMS/cisdata.jsp?kodcis=56
		$url = 'http://apl.czso.cz/iSMS/cisexp.jsp?kodcis=56&typdat=0&cisvaz=149&datpohl=08.07.2016&cisjaz=203&format=0';
		if (!$this->downloadFile($url, 'nomenc-cz-tobe.xml'))
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
						'id' => 'cz-tobe-'.$item['CHODNOTA'],
						'itemId' => $item['CHODNOTA'],
						'level' => 0,
						'order' => $item['CHODNOTA'],
						'shortName' => str::toDb($item['ZKRTEXT']),
						'fullName' => str::toDb($item['TEXT']),
						'docState' => 4000, 'docStateMain' => 2,
				];
				if ($item['ADMPLOD'] != '1900-01-01')
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
}

