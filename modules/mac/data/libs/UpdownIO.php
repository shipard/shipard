<?php

namespace mac\data\libs;
use \Shipard\Base\Utility;


/**
 * @class UpdownIO
 */
class UpdownIO extends Utility
{
  var $apiKey = '';

  var $curl = NULL;

  public function setApiKey(string $apiKey)
  {
    $this->apiKey = $apiKey;
  }

  public function loadCheck(string $checkToken)
  {
    $url = 'https://updown.io/api/checks/'.$checkToken.'?metrics=1';
    $result = $this->apiCall($url);
    return $result;
  }

	public function apiCall ($url)
	{
		if (!$this->curl)
		{
			$this->curl = curl_init();
			curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
				'Connection: Keep-Alive',
				'Keep-Alive: 60',
				'X-API-KEY: ' . $this->apiKey,
			]);
			curl_setopt ($this->curl, CURLOPT_RETURNTRANSFER, TRUE);
		}

		curl_setopt ($this->curl, CURLOPT_URL, $url);
		$resultCode = curl_exec ($this->curl);
		$resultData = json_decode ($resultCode, TRUE);

		return $resultData;
	}

  public function close()
  {
		if ($this->curl)
    	curl_close ($this->curl);
  }
}
