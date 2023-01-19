<?php

namespace lib\nomenclature\country\cz;
ini_set('memory_limit','256M');

use \lib\nomenclature\ImportNomenclature, \e10\str;


/**
 * Class ImportCZORP
 */
class ImportCZORP extends ImportNomenclature
{
	var $nomecTypeNdx = 0;
  var $destFileNameCore;
  var $destFileNameLinks;
  var $destFileNameLinks2;

	public function run()
	{
    $srcUrl = 'https://apl.czso.cz/iSMS/cisexp.jsp?kodcis=65&typdat=0&cisvaz=80007_97&datpohl=18.01.2023&cisjaz=203&format=0';
		if (!$this->downloadFile($srcUrl, 'nomenc-cz-orp-core.xml'))
			return;
    $this->destFileNameCore = $this->destFileName;

    $srcUrl = 'https://apl.czso.cz/iSMS/cisexp.jsp?kodcis=65&typdat=1&cisvaz=43_1182&datpohl=18.01.2023&cisjaz=203&format=0';
		if (!$this->downloadFile($srcUrl, 'nomenc-cz-orp-links.xml'))
			return;
    $this->destFileNameLinks = $this->destFileName;

    $srcUrl = 'https://apl.czso.cz/iSMS/cisexp.jsp?kodcis=47&typdat=1&cisvaz=43_1265&datpohl=18.01.2023&cisjaz=203&format=0';
		if (!$this->downloadFile($srcUrl, 'nomenc-cz-orp-links2.xml'))
			return;
    $this->destFileNameLinks2 = $this->destFileName;

		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-orp')->fetch();
		if ($nomencType)
			$this->nomecTypeNdx = $nomencType['ndx'];

		$this->import();
	}

	protected function import ()
	{
    //$this->db()->query('DELETE FROM [e10_base_nomencItems] WHERE [nomencType] = %i', $this->nomecTypeNdx);

		$data = file_get_contents($this->destFileNameCore);
		$simpleXml = simplexml_load_string($data);
		$json = json_decode (json_encode($simpleXml), TRUE);

		foreach ($json['DATA'] as $d)
		{
			foreach ($d as $item)
			{
				$this->importItem($item, NULL, 1, NULL);
			}
		}

		$data = file_get_contents($this->destFileNameLinks);
		$simpleXml = simplexml_load_string($data);
		$json = json_decode (json_encode($simpleXml), TRUE);

		foreach ($json['DATA'] as $d)
		{
			foreach ($d as $item)
			{
				$this->importItemLink($item, NULL, 1, NULL);
			}
		}

    /*
		$data = file_get_contents($this->destFileNameLinks2);
		$simpleXml = simplexml_load_string($data);
		$json = json_decode (json_encode($simpleXml), TRUE);

		foreach ($json['DATA'] as $d)
		{
			foreach ($d as $item)
			{
				$this->importItemLink2($item, NULL, 1, NULL);
			}
		}
    */
  }

	protected function importItem ($item)
	{
    $level = 1;
    $order = $item['CHODNOTA'];
    		$newItem = [
				'nomencType' => $this->nomecTypeNdx,
				'id' => 'cz-orp-'.$item['CHODNOTA'],
				'itemId' => 'CZ'.$item['CHODNOTA'],
				'level' => $level,
				'order' => $order,
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

		$newItem['ndx'] = $newItemNdx;
	}

  protected function importItemLink ($item, $ownerItem, $level, $mainItem)
	{
    $itemTo = $item['POLVAZ'][0];
    $itemFrom = $item['POLVAZ'][1];

    $mainItem = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [id] = %s', 'cz-orp-'.$itemTo['CHODNOTA'])->fetch();
    if (!$mainItem)
    {
      echo "ERROR - missing main id `".'cz-orp-'.$itemTo['CHODNOTA']."`\n";
      return;
    }

    //echo "* ".$itemFrom['CHODNOTA'].'/'.$itemFrom['TEXT'].' -> '.$itemTo['CHODNOTA'].' --> '.$itemTo['TEXT'].' | '.$mainItem['ndx']."\n";

    $level = 2;
		$order = $mainItem['itemId'].'.'.$itemFrom['CHODNOTA'];
		$newItem = [
				'nomencType' => $this->nomecTypeNdx,
				'id' => 'cz-orp-'.$itemFrom['CHODNOTA'],
				'itemId' => 'CZ'.$itemFrom['CHODNOTA'],
				'level' => $level,
				'order' => $order,
        'ownerItem' => $mainItem['ndx'],
				'shortName' => str::toDb($itemFrom['TEXT']),
				'fullName' => str::toDb($itemFrom['TEXT']).', '.str::toDb($itemTo['TEXT']),
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

		$newItem['ndx'] = $newItemNdx;
	}

  protected function importItemLink2 ($item, $ownerItem, $level, $mainItem)
	{
    $itemFrom = $item['POLVAZ'][0];
    $itemTo = $item['POLVAZ'][1];

    $ownerItem = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [nomencType] = %i', $this->nomecTypeNdx,
                                    'AND [itemId] = %s', $itemTo['CHODNOTA'])->fetch();
    if (!$ownerItem)
    {
      echo "ERROR - missing owner id `".'cz-orp-'.$itemTo['CHODNOTA']."`\n";
      return;
    }

    $mainItem = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [ndx] = %i', $ownerItem['ownerItem'])->fetch();
    if (!$mainItem)
    {
      echo "ERROR - missing main id `#".$ownerItem['ownerItem']."`\n";
      return;
    }

    $level = 3;
		$order = $mainItem['itemId'].'.'.$ownerItem['itemId'].'.'.$itemFrom['CHODNOTA'];

    //echo "* ".$order.' / '.$itemFrom['TEXT'].' -> '.$ownerItem['fullName'].' --> '.$mainItem['fullName']."\n";

    $newItem = [
				'nomencType' => $this->nomecTypeNdx,
				'id' => 'cz-orp-'.$order,
				'itemId' => $itemFrom['CHODNOTA'],
				'level' => $level,
				'order' => $order,
        'ownerItem' => $ownerItem['ndx'],
				'shortName' => str::toDb($itemFrom['TEXT']),
				'fullName' => str::toDb($itemFrom['TEXT']).', '.str::toDb($itemTo['TEXT']),
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

		$newItem['ndx'] = $newItemNdx;
	}
}
