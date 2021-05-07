<?php

namespace integrations\hooks\in\services;
use e10\Utility, e10\utils, e10\json;


/**
 * Class HookCore
 * @package integrations\hooks\in\services
 */
class HookCore extends Utility
{
	var $inRecData = NULL;
	var $inParams = NULL;
	var $inPayload = NULL;

	var $hookRecData = NULL;
	var $hookSettings = NULL;

	var $protocolAll = NULL;
	var $protocolThis = [];

	function parseRecData()
	{
		// -- hook settings
		$this->hookRecData = $this->app()->loadItem($this->inRecData['hook'], 'integrations.hooks.in.hooks');
		if ($this->hookRecData && $this->hookRecData['hookSettings'] !== '')
			$this->hookSettings = json_decode($this->hookRecData['hookSettings'], TRUE);
		if (!$this->hookSettings)
			$this->hookSettings = [];

		// -- params
		if (isset($this->inRecData['params']) && $this->inRecData['params'] !== '')
			$this->inParams = json_decode($this->inRecData['params'], TRUE);

		if (!$this->inParams)
			$this->inParams = [];

		// -- payload
		if (isset($this->inRecData['payload']) && $this->inRecData['payload'] !== '')
			$this->inPayload = json_decode($this->inRecData['payload'], TRUE);

		if (!$this->inPayload)
			$this->inPayload = [];

		// -- protocol
		if (isset($this->inRecData['protocol']) && $this->inRecData['protocol'] !== '')
			$this->protocolAll = json_decode($this->inRecData['protocol'], TRUE);

		if (!$this->protocolAll)
			$this->protocolAll = [];

		$this->protocolThis = ['dt' => utils::now('Y-m-d H:i:s')];
	}

	public function init()
	{
		$this->parseRecData();
	}

	public function setRecData($recData)
	{
		$this->inRecData = $recData;
		$this->init();
	}

	public function setResult($resultData)
	{
		$this->protocolThis['result'] = $resultData;

		$this->protocolAll[] = $this->protocolThis;
		$update = ['protocol' => json::lint($this->protocolAll), 'hookState' => 9];
		if (isset($resultData['status']) && $resultData['status'] === 0)
			$update['hookState'] = 2;

		$this->db()->query('UPDATE [integrations_hooks_in_data] SET ', $update, ' WHERE [ndx] = %i', $this->inRecData['ndx']);
	}

	public function run()
	{
		//echo "Abstract hook running.... \n";
	}
}
