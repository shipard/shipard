<?php

namespace lib\nomenclature\country\cz;

use \lib\nomenclature\ImportNomenclature, \e10\json;


/**
 * Class ImportCZZUJ
 * @package lib\nomenclature\country\cz
 */
class ImportCZZUJ extends ImportNomenclature
{
	var $srcUrl = 'https://apl.czso.cz/iSMS/cisexp.jsp?kodcis=51&typdat=1&cisvaz=109&datpohl=06.04.2022&cisjaz=203&format=0';
	var $nomecTypeNdx = 0;
	var $nutsTypeNdx;
	
	public function run()
	{
		if (!$this->downloadFile($this->srcUrl, 'nomenc-cz-zuj.xml'))
			return;

		$zujType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-zuj')->fetch();
		if ($zujType)
			$this->nomecTypeNdx = $zujType['ndx'];

		$nutsType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nuts')->fetch();
		if ($nutsType)
			$this->nutsTypeNdx = $nutsType['ndx'];

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
				$this->importItem($item);
			}
		}
	}

	protected function importItem ($item)
	{
		$ownerItemNdx = 0;
		$ownerId = $item['POLVAZ'][1]['CHODNOTA'];

		$ownerRec = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [nomencType] = %i', $this->nutsTypeNdx,
			'AND [itemId] = %s', $ownerId)->fetch();
		if ($ownerRec)
			$ownerItemNdx = $ownerRec['ndx'];
		else
			echo " -- NUTS $ownerId not found: ".json::lint ($item)."\n";

		$order = $item['POLVAZ'][0]['CHODNOTA'];
		$newItem = [
			'nomencType' => $this->nomecTypeNdx,
			'id' => 'cz-zuj-'.$item['POLVAZ'][0]['CHODNOTA'],
			'itemId' => $item['POLVAZ'][0]['CHODNOTA'],
			'level' => 0,
			'order' => $order,
			'shortName' => $item['POLVAZ'][0]['TEXT'],
			'fullName' => $item['POLVAZ'][0]['TEXT'],
			'ownerItem' => $ownerItemNdx,
			'docState' => 4000, 'docStateMain' => 2,
		];


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
