<?php

namespace services\sw\libs;

use \e10\utils, \e10\json, \e10\Utility, \e10\Response;


/**
 * Class SWInfoFeed
 * @package services\sw\libs
 *
 * https://sw.shipard.services/feed/sw-info/test
 */
class SWInfoFeed extends Utility
{
	/** @var \services\sw\libs\SWInfoAnalyzer */
	var $analyzer;

	var $object = [];
	var $data;

	public function init()
	{
		$dataStr = $this->app()->postData();
		$this->data = json_decode($dataStr, TRUE);

//		error_log("--FEED: ".json_encode($this->data));

		$this->object['success'] = 0;
		$this->object['info'] = [];
	}

	function test()
	{
		if (!$this->data)
			return FALSE;

		$this->analyzer = new \services\sw\libs\SWInfoAnalyzer($this->app());
		$this->analyzer->setSrcData($this->data);
		$this->analyzer->run();

		if ($this->analyzer->error)
			return FALSE;

		$this->object['info']['sw'] = ['swSUID' => $this->analyzer->swSUID];
		$this->object['info']['swVersion'] = ['swVersionSUID' => $this->analyzer->swVersionSUID];
		$this->object['success'] = 1;

		return TRUE;
	}

	public function addToQueue()
	{
		if (!$this->data)
			return;

		$dataStr = json::lint($this->data);
		$checkSum = sha1($dataStr);
		$now = new \DateTime();

		$exist = $this->db()->query('SELECT ndx, cntSameAsOriginal FROM [services_sw_swQueue]',
			' WHERE docState = %i', 1000, ' AND [checksum] = %s', $checkSum)->fetch();

		if ($exist)
		{
			$update = [
				'title' => $this->infoTitle(),
				'dateSameAsOriginal' => $now,
				'cntSameAsOriginal' => $exist['cntSameAsOriginal'] + 1,
			];

			$this->db()->query('UPDATE [services_sw_swQueue] SET ', $update, ' WHERE ndx = %i', $exist['ndx']);
		}
		else
		{
			$newItem = [
				'title' => $this->infoTitle(),
				'data' => $dataStr, 'checksum' => $checkSum,
				'dateCreate' => $now,
				'dateSameAsOriginal' => $now, 'cntSameAsOriginal' => 0,
				'docState' => 1000, 'docStateMain' => 0,
			];

			$this->db()->query('INSERT INTO [services_sw_swQueue]', $newItem);
		}
	}

	protected function infoTitle()
	{
		if (isset($this->data['NameClean']))
			return $this->data['NameClean'];
		if (isset($this->data['Name']))
			return $this->data['Name'];
		if (isset($this->data['DisplayName']))
			return $this->data['DisplayName'];
		if (isset($this->data['WindowsProductName']))
			return $this->data['WindowsProductName'];
		if (isset($this->data['Operating System']))
			return $this->data['Operating System'];
		if (isset($this->data['osName']))
			return $this->data['osName'];

		return '???';
	}

	public function run ()
	{
		if (!$this->test())
			$this->addToQueue();

		$response = new Response ($this->app);
		$data = json::lint ($this->object);
		$response->setRawData($data);
		return $response;
	}
}
