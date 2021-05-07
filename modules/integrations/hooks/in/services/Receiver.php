<?php

namespace integrations\hooks\in\services;

use \e10\Utility, e10\utils, e10\json;


/**
 * Class Receiver
 * @package integrations\hooks\in\services
 */
class Receiver extends Utility
{
	var $hookNdx = 0;
	var $hookRecData = NULL;

	var $recData = [];
	var $params;
	var $payload;

	public function init()
	{
		$urlPart1 = $this->app()->requestPath(1);
		$urlPart2 = $this->app()->requestPath(2);

		$hook = $this->db()->query ('SELECT * FROM [integrations_hooks_in_hooks] WHERE [urlPart1] = %s', $urlPart1,
			' AND [urlPart2] = %s', $urlPart2, ' AND [docState] = %i', 4000)->fetch();
		if ($hook)
		{
			$this->hookNdx = $hook['ndx'];
			$this->hookRecData = $hook->toArray();
		}
	}

	function storeParams()
	{
		$this->params = [];

		$this->params['headers'] = utils::getAllHeaders();
	}

	function storePayload()
	{
		$this->payload = [];

		$decoded = 0;
		$postData = $this->app()->postData ();

		if (isset($this->params['headers']['content-type']))
		{
			if ($this->params['headers']['content-type'] === 'application/json')
			{
				$data = json_decode($postData, TRUE);
				if ($data)
				{
					$this->payload['data'] = $data;
					$decoded = 1;
				}
			}
		}

		if (!$decoded)
			$this->payload['postData'] = $postData;
	}

	function save()
	{
		$this->recData['hook'] = $this->hookNdx;

		$ipAddress = (isset($_SERVER ['REMOTE_ADDR'])) ? $_SERVER ['REMOTE_ADDR'] : '0.0.0.0';
		$this->recData['ipAddress'] = $ipAddress;

		$this->recData['dateCreate'] = new \DateTime();
		$this->recData['params'] = json::lint($this->params);
		$this->recData['payload'] = json::lint($this->payload);

		$this->db()->query ('INSERT INTO [integrations_hooks_in_data] ', $this->recData);
		$hookDataNdx = intval ($this->db()->getInsertId ());
		if ($hookDataNdx)
			$this->doIt($hookDataNdx);
	}

	function doIt ($hookDataNdx)
	{
		$ite = new \integrations\hooks\in\services\RunHooks($this->app);
		$ite->run($hookDataNdx);
	}

	public function createResponseContent($response)
	{
		$this->init();
		if ($this->hookNdx)
		{
			$this->storeParams();
			$this->storePayload();
			$this->save();

			$response->setRawData(json::lint(['success' => 1]));
			$response->setStatus(200);
		}
		else
		{
			$response->setRawData(json::lint(['success' => 0]));
			$response->setStatus(404);
		}
	}
}
