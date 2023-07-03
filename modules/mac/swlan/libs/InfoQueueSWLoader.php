<?php

namespace mac\swlan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class InfoQueueSWLoader
 * @package mac\swlan\libs
 */
class InfoQueueSWLoader extends Utility
{
	/** @var \mac\swlan\TableInfoQueue */
	var $tableInfoQueue;

	var $recData = NULL;

	var $swNdx = 0;
	var $swVersionNdx = 0;

	public function init()
	{
		$this->tableInfoQueue = $this->app()->table('mac.swlan.infoQueue');
	}

	protected function doOne($ndx)
	{
		$this->swNdx = 0;
		$this->swVersionNdx = 0;

		$this->recData = $this->tableInfoQueue->loadItem($ndx);
		if (!$this->recData)
			return;

		$data = json_decode($this->recData['dataSanitized']);
		if (!$data)
			return;

		//$urlCore = 'https://system.shipard.app/';
		$urlCore = 'https://org.shipard.app/';

		$urlFull = $urlCore . 'feed/sw-get/' . $this->recData['swSUID'];

		$response = utils::http_get($urlFull);
		$responseContent = FALSE;
		if (isset($response['content']))
			$responseContent = json_decode($response['content'], TRUE);


		if (!$responseContent || !isset($responseContent['success']) || !$responseContent['success'])
			return;

		//echo json::lint($responseContent) . "\n";

		if (isset($responseContent['info']['publisher']))
		{
			$this->savePublisher($responseContent['info']['publisher']);
		}

		if (isset($responseContent['info']['categories']))
		{
			$this->saveCategories($responseContent['info']['categories']);
		}

		$this->saveSW($responseContent['info']['sw'], $responseContent['info']['swVersions'],
			$responseContent['info']['swCategories'], $responseContent['info']['swNames'], $responseContent['info']['swIds']);


		$ok = 1;
		$existedSW = $this->db()->query('SELECT ndx FROM [mac_sw_sw] WHERE [suid] = %s', $this->recData['swSUID'])->fetch();
		if ($existedSW)
			$this->swNdx = $existedSW['ndx'];
		if ($this->recData['swVersionSUID'] !== '')
		{
			$existedSWVersion = $this->db()->query('SELECT ndx FROM [mac_sw_swVersions] WHERE [suid] = %s', $this->recData['swVersionSUID'])->fetch();
			if ($existedSWVersion)
				$this->swVersionNdx = $existedSWVersion['ndx'];
			else
				$ok = 0;
		}

		if (!$ok)
			return;

		$this->updateDeviceSW ($this->recData['device'], $this->swNdx, $this->swVersionNdx);

		$update = [
			'swNdx' => $this->swNdx,
			'swVersionNdx' => $this->swVersionNdx,
			'docState' => 4000, 'docStateMain' => 2,
		];
		$this->db()->query('UPDATE [mac_swlan_infoQueue] SET ', $update, ' WHERE [ndx] = %i', $this->recData['ndx']);
	}

	protected function savePublisher($publisherData)
	{
		if (isset($publisherData['ndx']))
			unset($publisherData['ndx']);

		$exist = $this->db()->query('SELECT ndx FROM [mac_sw_publishers] WHERE [suid] = %s', $publisherData['suid'])->fetch();
		if ($exist)
		{
			$this->db()->query('UPDATE [mac_sw_publishers] SET ', $publisherData, ' WHERE ndx = %i', $exist['ndx']);
		}
		else
		{
			$this->db()->query('INSERT INTO [mac_sw_publishers] ', $publisherData);
		}
	}

	protected function saveCategories($categoriesData)
	{
		foreach ($categoriesData as $catData)
		{
			$exist = $this->db()->query('SELECT ndx FROM [mac_sw_categories] WHERE [suid] = %s', $catData['suid'])->fetch();
			if ($exist)
			{
				$this->db()->query('UPDATE [mac_sw_categories] SET ', $catData, ' WHERE ndx = %i', $exist['ndx']);
			}
			else
			{
				$this->db()->query('INSERT INTO [mac_sw_categories] ', $catData);
			}
		}
	}

	protected function saveSW($swData, $swVersionsData, $swCategoriesData, $swNamesData, $swIdsData)
	{
		$pkErrors = [];

		// -- SW
		$swNdx = 0;
		$this->app()->checkPrimaryKeys('mac.sw.sw', $swData, $pkErrors);
		if (count($pkErrors))
			echo json::lint($pkErrors)."\n";

		$swData['external'] = 1;

		$exist = $this->db()->query('SELECT ndx FROM [mac_sw_sw] WHERE [suid] = %s', $swData['suid'])->fetch();
		if ($exist)
		{
			$swNdx = $exist['ndx'];
			$this->db()->query('UPDATE [mac_sw_sw] SET ', $swData, ' WHERE ndx = %i', $exist['ndx']);
		}
		else
		{
			$this->db()->query('INSERT INTO [mac_sw_sw] ', $swData);
			$swNdx = intval ($this->app()->db()->getInsertId ());
		}

		// -- VERSIONS
		$usedPks = [];
		foreach ($swVersionsData as $verData)
		{
			$pkErrors = [];
			$this->app()->checkPrimaryKeys('mac.sw.swVersions', $verData, $pkErrors);
			if (count($pkErrors))
				echo json::lint($pkErrors)."\n";

			$exist = $this->db()->query('SELECT ndx FROM [mac_sw_swVersions] WHERE [suid] = %s', $verData['suid'])->fetch();
			if ($exist)
			{
				$this->db()->query('UPDATE [mac_sw_swVersions] SET ', $verData, ' WHERE ndx = %i', $exist['ndx']);
				$usedPks[] = $exist['ndx'];
			}
			else
			{
				$this->db()->query('INSERT INTO [mac_sw_swVersions] ', $verData);
				$usedPks[] = intval ($this->app()->db()->getInsertId ());
			}
		}
		// -- delete unused
		if (count($usedPks))
			$this->db()->query('DELETE FROM [mac_sw_swVersions] WHERE [sw] = %i', $swNdx, ' AND [ndx] NOT IN %in', $usedPks);
		else
			$this->db()->query('DELETE FROM [mac_sw_swVersions] WHERE [sw] = %i', $swNdx);

		// -- NAMES
		$usedPks = [];
		foreach ($swNamesData as $name)
		{
			$exist = $this->db()->query('SELECT ndx, name FROM [mac_sw_swNames] WHERE [name] = %s', $name, ' AND [sw] = %i', $swNdx)->fetch();
			if ($exist)
			{
				if ($exist['name'] !== $name)
					$this->db()->query('UPDATE [mac_sw_swNames] SET [name] = %s', $name, ' WHERE ndx = %i', $exist['ndx']);
				$usedPks[] = $exist['ndx'];
			}
			else
			{
				$newItem = ['name' => $name, 'sw' => $swNdx];
				$this->db()->query('INSERT INTO [mac_sw_swNames] ', $newItem);
				$usedPks[] = intval ($this->app()->db()->getInsertId ());
			}
		}
		// -- delete unused
		if (count($usedPks))
			$this->db()->query('DELETE FROM [mac_sw_swNames] WHERE [sw] = %i', $swNdx, ' AND [ndx] NOT IN %in', $usedPks);
		else
			$this->db()->query('DELETE FROM [mac_sw_swNames] WHERE [sw] = %i', $swNdx);


		// -- IDS
		$usedPks = [];
		foreach ($swIdsData as $id)
		{
			$exist = $this->db()->query('SELECT ndx, id FROM [mac_sw_swIds] WHERE [id] = %s', $id, ' AND [sw] = %i', $swNdx)->fetch();
			if ($exist)
			{
				if ($exist['id'] !== $id)
					$this->db()->query('UPDATE [mac_sw_swIds] SET [id] = %s', $name, ' WHERE ndx = %i', $exist['ndx']);
				$usedPks[] = $exist['ndx'];
			}
			else
			{
				$newItem = ['id' => $id, 'sw' => $swNdx];
				$this->db()->query('INSERT INTO [mac_sw_swIds] ', $newItem);
				$usedPks[] = intval ($this->app()->db()->getInsertId ());
			}
		}
		// -- delete unused
		if (count($usedPks))
			$this->db()->query('DELETE FROM [mac_sw_swIds] WHERE [sw] = %i', $swNdx, ' AND [ndx] NOT IN %in', $usedPks);
		else
			$this->db()->query('DELETE FROM [mac_sw_swIds] WHERE [sw] = %i', $swNdx);


		// SW CATEGORIES
		$usedCatsNdx = [];
		foreach ($swCategoriesData as $swCatId)
		{
			$existedCat = $this->db()->query('SELECT ndx FROM [mac_sw_categories] WHERE [suid] = %s', $swCatId)->fetch();
			if (!$existedCat)
				continue;
			$swCatNdx = $existedCat['ndx'];
			$usedCatsNdx[] = $swCatNdx;

			$existedDocLink = $this->db()->query('SELECT ndx',
				' FROM [e10_base_doclinks] AS docLinks',
				' WHERE srcTableId = %s', 'mac.sw.sw', 'AND dstTableId = %s', 'mac.sw.categories',
				' AND docLinks.linkId = %s', 'mac-sw-swCats', 'AND srcRecId = %i', $swNdx, ' AND dstRecId = %i', $swCatNdx)->fetch();
			if ($existedDocLink)
				continue;

			$docLink = [
				'linkId' => 'mac-sw-swCats', 'srcTableId' => 'mac.sw.sw', 'dstTableId' => 'mac.sw.categories',
				'srcRecId' => $swNdx, 'dstRecId' => $swCatNdx,
			];
			$this->db()->query('INSERT INTO [e10_base_doclinks]', $docLink);
		}
		// -- delete unused
		if (count($usedCatsNdx))
			$this->db()->query('DELETE FROM  [e10_base_doclinks]',
				' WHERE srcTableId = %s', 'mac.sw.sw', 'AND dstTableId = %s', 'mac.sw.categories',
				' AND linkId = %s', 'mac-sw-swCats', 'AND srcRecId = %i', $swNdx, ' AND dstRecId NOT IN %in', $usedCatsNdx);
		else
			$this->db()->query('DELETE FROM  [e10_base_doclinks]',
				' WHERE srcTableId = %s', 'mac.sw.sw', 'AND dstTableId = %s', 'mac.sw.categories',
				' AND linkId = %s', 'mac-sw-swCats', 'AND srcRecId = %i', $swNdx);
	}

	protected function updateDeviceSW ($deviceNdx, $swNdx, $swVersionNdx)
	{
		$existedDeviceSW = $this->db()->query('SELECT * FROM [mac_swlan_devicesSW] WHERE [device] = %i', $deviceNdx,
			' AND [sw] = %i', $swNdx)->fetch();
		if (!$existedDeviceSW)
		{
			$itemHistory = ['device' => $deviceNdx, 'sw' => $swNdx, 'swVersion' => $swVersionNdx, 'dateBegin' => new \DateTime(), 'active' => 1];
			$this->db()->query('INSERT INTO [mac_swlan_devicesSWHistory] ', $itemHistory);
			$historyNdx = intval ($this->app()->db()->getInsertId ());

			$itemSW = [
				'device' => $deviceNdx, 'sw' => $swNdx, 'swVersion' => $swVersionNdx, 'dateBegin' => $itemHistory['dateBegin'],
				'active' => 1, 'currentHistoryItem' => $historyNdx,
			];

			$this->db()->query('INSERT INTO [mac_swlan_devicesSW] ', $itemSW);
			return;
		}

		if ($existedDeviceSW['swVersion'] == $swVersionNdx)
		{

			return;
		}

		$now = new \DateTime();

		$updateDeviceSW = ['swVersion' => $swVersionNdx];
		$this->db()->query('UPDATE [mac_swlan_devicesSW] SET ', $updateDeviceSW, ' WHERE [ndx] = %i', $existedDeviceSW['ndx']);

		$update = ['active' => 0, 'dateEnd' => $now];
		$this->db()->query('UPDATE [mac_swlan_devicesSWHistory] SET ', $update, ' WHERE [ndx] = %i', $existedDeviceSW['currentHistoryItem']);

		$itemHistory = ['device' => $deviceNdx, 'sw' => $swNdx, 'swVersion' => $swVersionNdx, 'dateBegin' => $now, 'active' => 1];
		$this->db()->query('INSERT INTO [mac_swlan_devicesSWHistory] ', $itemHistory);
		$historyNdx = intval ($this->app()->db()->getInsertId ());

		$this->db()->query('UPDATE [mac_swlan_devicesSW] SET [currentHistoryItem] = %i', $historyNdx, ' WHERE ndx = %i', $existedDeviceSW['ndx']);
	}

	protected function doAll()
	{
		$q = [];
		array_push($q, 'SELECT ndx FROM [mac_swlan_infoQueue] ');
		array_push($q, ' WHERE [docState] = %i', 1200);
		//array_push($q, ' AND ([swSUID] = %s', '5JY4ZP', ' OR [swSUID] = %s)', '1KCWB9');
		array_push($q, ' ORDER BY ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->doOne($r['ndx']);
		}
	}

	public function run()
	{
		$this->doAll();
	}
}
