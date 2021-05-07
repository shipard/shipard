<?php

namespace lib\objects;
use e10\Utility, e10\json, e10\utils;


/**
 * Class ClientEngine
 * @package lib\objects
 */
class ClientEngine extends Utility
{
	var $apiUrl = '';
	var $apiKey = '';
	var $curl = NULL;

	public function apiCall ($url, $data = NULL)
	{
		if (!$this->curl)
		{
			$this->curl = curl_init();
			curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
				'Connection: Keep-Alive',
				'Keep-Alive: 300',
				'e10-api-key: ' . $this->apiKey,
				'e10-device-id: ' . utils::machineDeviceId()
			]);
			curl_setopt ($this->curl, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt ($this->curl, CURLOPT_POST, true);
		}

		curl_setopt ($this->curl, CURLOPT_URL, $url);
		curl_setopt ($this->curl, CURLOPT_POSTFIELDS, json::encode($data));
		$resultCode = curl_exec ($this->curl);
		$resultData = json_decode ($resultCode, TRUE);

		return $resultData;
	}

	function uploadDoc ($tableId, $data)
	{
		$url = $this->apiUrl;
		$url .= '/api/objects/import/';
		$url .= $tableId;

		$result = $this->apiCall($url, $data);

		if (!isset($result['status']) || !$result['status'])
		{
			echo "ERROR: " . json::encode($result) . "\n";
			echo json::lint ($data)."\n---------------------------------\n";
		}

		return $result;
	}

	function uploadDocs ($tableId, $data)
	{
		foreach($data as $oneItem)
		{
			if (isset($oneItem['ndx']))
				unset ($oneItem['ndx']);
			$this->uploadDoc($tableId, $oneItem);
		}
	}
}
