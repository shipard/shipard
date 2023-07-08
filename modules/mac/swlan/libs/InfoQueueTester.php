<?php

namespace mac\swlan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class InfoQueueTester
 * @package mac\swlan\libs
 */
class InfoQueueTester extends Utility
{
	/** @var \mac\swlan\TableInfoQueue */
	var $tableInfoQueue;

	var $recData = NULL;

	public function init()
	{
		$this->tableInfoQueue = $this->app()->table('mac.swlan.infoQueue');
	}

	protected function doOne($ndx)
	{
		$this->recData = $this->tableInfoQueue->loadItem($ndx);
		if (!$this->recData)
			return;

		$data = json_decode($this->recData['dataSanitized']);
		if (!$data)
			return;

		//$urlCore = 'https://system.shipard.app/';
		$urlCore = 'https://org.shipard.app/';

		$urlFull = $urlCore.'feed/sw-info/'.$this->recData['checksumSanitized'];

		$response = utils::http_post($urlFull, $this->recData['dataSanitized']);
		$responseContent = FALSE;
		if (isset($response['content']))
			$responseContent = json_decode($response['content'], TRUE);

		//echo json::lint($responseContent)."\n";

		if ($responseContent && isset($responseContent['success']) && $responseContent['success'])
		{
			$update = [
				'swSUID' => $responseContent['info']['sw']['swSUID'],
				'swVersionSUID' => $responseContent['info']['swVersion']['swVersionSUID'],
				'docState' => 1200, 'docStateMain' => 1,
			];
			$this->db()->query('UPDATE [mac_swlan_infoQueue] SET ', $update, ' WHERE [ndx] = %i', $this->recData['ndx']);

		}
	}

	protected function doAll()
	{
		$q = [];
		array_push($q, 'SELECT ndx FROM [mac_swlan_infoQueue] ');
		array_push($q, ' WHERE [docState] = %i', 1000);
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
