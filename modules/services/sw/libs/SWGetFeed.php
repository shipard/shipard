<?php

namespace services\sw\libs;

use \e10\utils, \e10\json, \e10\Utility, \e10\Response;


/**
 * Class SWGetFeed
 * @package services\sw\libs
 *
 * https://sw.shipard.services/feed/sw-get/SW-SUID
 */
class SWGetFeed extends Utility
{
	var $swSUID = '';
	var $object = [];
	var $data;

	var $recDataSW = NULL;
	var $recDataPublisher = NULL;
	var $recDataSWVersions = [];
	var $recDataSWCategories = [];
	var $recDataSWNames = [];
	var $recDataSWIds = [];
	var $recDataCategories = [];

	/** @var \mac\sw\TableSW */
	var $tableSW;
	/** @var \mac\sw\TableSWVersions */
	var $tableSWVersions;
	/** @var \mac\sw\TablePublishers */
	var $tablePublishers;
	/** @var \mac\sw\TableCategories */
	var $tableCategories;

	public function init()
	{
		$this->swSUID = $this->app->requestPath(2);

//		error_log("--FEED: ".json_encode($this->data));

		$this->tableSW = $this->app()->table('mac.sw.sw');
		$this->tableSWVersions = $this->app()->table('mac.sw.swVersions');
		$this->tablePublishers = $this->app()->table('mac.sw.publishers');
		$this->tableCategories = $this->app()->table('mac.sw.categories');

		$this->object['success'] = 0;
		$this->object['info'] = [];
	}

	function load()
	{
		if (!$this->swSUID || $this->swSUID === '')
		{
			$this->object['msg'] = 'Invalid SUID';
			return FALSE;
		}
		$this->loadSW();

		if (!$this->recDataSW)
		{
			$this->object['msg'] = 'Invalid SW docState';
			return FALSE;
		}

		$this->loadSWVersions();
		$this->loadSWCategories();
		$this->loadSWNames();
		$this->loadSWIds();
		$this->loadPublisher();

		unset($this->recDataSW['ndx']);

		if ($this->recDataPublisher)
			$this->object['info']['publisher'] = $this->recDataPublisher;
		if (count($this->recDataCategories))
			$this->object['info']['categories'] = $this->recDataCategories;

		$this->object['info']['sw'] = $this->recDataSW;
		$this->object['info']['swVersions'] = $this->recDataSWVersions;
		$this->object['info']['swCategories'] = $this->recDataSWCategories;
		$this->object['info']['swNames'] = $this->recDataSWNames;
		$this->object['info']['swIds'] = $this->recDataSWIds;

		$this->object['success'] = 1;

		return TRUE;
	}

	function loadSW()
	{
		$this->recDataSW = $this->tableSW->loadRecData('@suid:'.$this->swSUID);
		if ($this->recDataSW['docState'] != 4000)
		{
			$this->recDataSW = NULL;
			return;
		}
	}

	function loadSWVersions()
	{
		$q = [];
		array_push($q, 'SELECT * FROM [mac_sw_swVersions]');
		array_push($q, ' WHERE [sw] = %i', $this->recDataSW['ndx']);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$versionItem = $r->toArray();
			json::polish($versionItem);
			$versionItem['sw'] = '@suid:'.$this->recDataSW['suid'];
			unset($versionItem['ndx']);
			$this->recDataSWVersions[] = $versionItem;
		}
	}

	function loadSWNames()
	{
		$q = [];
		array_push($q, 'SELECT * FROM [mac_sw_swNames]');
		array_push($q, ' WHERE [sw] = %i', $this->recDataSW['ndx']);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->recDataSWNames[] = $r['name'];
		}
	}

	function loadSWIds()
	{
		$q = [];
		array_push($q, 'SELECT * FROM [mac_sw_swIds]');
		array_push($q, ' WHERE [sw] = %i', $this->recDataSW['ndx']);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->recDataSWIds[] = $r['id'];
		}
	}

	function loadSWCategories()
	{
		$q[] = 'SELECT docLinks.*, [cats].suid, [cats].icon';
		array_push($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push($q, ' LEFT JOIN [mac_sw_categories] AS [cats] ON docLinks.dstRecId = [cats].ndx');
		array_push($q, ' WHERE srcTableId = %s', 'mac.sw.sw', 'AND dstTableId = %s', 'mac.sw.categories');
		array_push($q, ' AND docLinks.linkId = %s', 'mac-sw-swCats', 'AND srcRecId = %i', $this->recDataSW['ndx']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->recDataSWCategories[] = $r['suid'];
			$category = $this->tableCategories->loadItem($r['dstRecId']);
			if ($category)
			{
				json::polish($category);
				unset($category['ndx']);
				$this->recDataCategories[] = $category;
			}
		}
	}

	function loadPublisher()
	{
		if ($this->recDataSW && $this->recDataSW['publisher'])
		{
			$this->recDataPublisher = $this->tablePublishers->loadItem($this->recDataSW['publisher']);
			if ($this->recDataPublisher)
				$this->recDataSW['publisher'] = '@suid:'.$this->recDataPublisher['suid'];
		}
	}

	public function run ()
	{
		$this->load();

		$response = new Response ($this->app);
		$data = json::lint ($this->object);
		$response->setRawData($data);
		return $response;
	}
}
