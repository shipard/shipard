<?php

namespace mac\swlan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class DeviceInfo
 * @package mac\lan\libs
 */
class InfoQueueParser extends Utility
{
	var $srcText;
	var $srcRows = [];
	var $tmpFileName = '';
	var $reUploadFrom = '';

	var $dataOriginal = [
		'info' => [],
		'sw' => [],
	];

	var $info = [];

	var $currentPartUserId = '';
	var $currentPartIsOSInfo = FALSE;
	var $currentPartValues = [];
	var $currentPartDPPos = -1;
	var $currentValue = '';
	var $currentKey = '';
	var $currentTitle = '';
	var $spaces = '';

	public function setSrcText($srcText)
	{
		$this->srcText = $srcText;
		$this->srcRows = preg_split("/\\r\\n|\\r|\\n/", $this->srcText);

		$this->tmpFileName = utils::tmpFileName('txt', 'shipard-agent');
		file_put_contents($this->tmpFileName, $srcText);
	}

	function checkSystemInfo($row)
	{
		if (substr($row, 3, 14) === 'shipard-agent:')
		{ // ;;;shipard-agent: 0.8.6
			$this->dataOriginal['info']['shipardAgent'] = $row;
			$this->info['shipardAgentVersion'] = trim(substr($row, 17));
		}
		elseif (substr($row, 3, 3) === 'os:')
		{ // ;;;os: windows
			$this->dataOriginal['info']['os'] = $row;
			$this->info['osType'] = trim(substr($row, 7));
		}
		elseif (substr($row, 3, 10) === 'deviceUid:')
		{ // ;;;deviceUid: ahsdjkh-safasfgjg-jhsgfjhgsf
			$this->dataOriginal['info']['deviceUid'] = $row;
			$this->info['deviceUid'] = trim(substr($row, 13));
		}
		elseif (substr($row, 3, 10) === 'deviceNdx:')
		{ // ;;;deviceNdx: 12345
			$this->dataOriginal['info']['deviceNdx'] = $row;
			$this->info['deviceNdx'] = trim(substr($row, 13));
		}
		elseif (substr($row, 3, 5) === 'date:')
		{ // ;;;date: 20200918T084337
			$this->dataOriginal['info']['date'] = $row;
			$this->info['date'] = trim(substr($row, 9));
		}
		elseif ($row === ';;;shipard-agent-system-info')
		{ // ;;;shipard-agent-system-info
			$this->currentPartIsOSInfo = TRUE;
		}
		elseif ($row === ';;;shipard-agent-sw-lm')
		{ // ;;;shipard-agent-sw-lm
			$this->currentPartIsOSInfo = FALSE;
			$this->currentPartUserId = '';
		}
		elseif (substr($row, 3, 22) === 'shipard-agent-sw-user=')
		{ // ;;;shipard-agent-sw-user=userId
			$this->currentPartIsOSInfo = FALSE;
			$this->currentPartUserId = trim(substr($row, 25));
		}
	}

	function addCurrentPartValue($row)
	{
		if ($this->currentPartDPPos !== -1 && substr($row, 0, $this->currentPartDPPos) === $this->spaces)
		{ // continued
			$this->currentValue .= trim($row);
			return;
		}

		$this->currentPartDPPos = strpos($row, ' :');
		if ($this->currentPartDPPos === FALSE)
			$this->currentPartDPPos = strpos($row, ': ');
		if ($this->currentPartDPPos === FALSE)
		{
			return;
		}

		if ($this->currentKey !== '' && $this->currentValue !== '')
			$this->currentPartValues[$this->currentKey] = $this->currentValue;

		if ($this->currentKey === 'WindowsProductName' || $this->currentKey === 'Name' || $this->currentKey === 'DisplayName' || $this->currentKey === 'Operating System' || $this->currentKey === 'osName')
			$this->currentTitle = $this->currentValue;

		$this->spaces = str_repeat(' ', $this->currentPartDPPos);
		$this->currentKey = trim(substr($row, 0, $this->currentPartDPPos));
		$this->currentValue = trim(substr($row, $this->currentPartDPPos + 2));
	}

	function checkAddPart()
	{
		if (!count($this->currentPartValues))
			return;

		$this->currentPartValues['_saOS'] = $this->info['osType'];

		$newPart = [
			'userId' => $this->currentPartUserId,
			'isOSInfo' => $this->currentPartIsOSInfo,
			'values' => $this->currentPartValues,
			'title' => $this->currentTitle,
			'json' => json::lint($this->currentPartValues)
		];

		$newPart['checkSum'] = sha1($newPart['json']);

		$this->dataOriginal['sw'][] = $newPart;

		$this->currentPartValues = [];
		$this->currentPartDPPos = -1;
		$this->currentValue = '';
		$this->currentKey = '';
		$this->currentTitle = '';
		$this->spaces = '';
	}

	public function createDataOriginal()
	{
		foreach ($this->srcRows as $r)
		{
			if (trim($r) === '')
			{
				$this->checkAddPart();
				continue;
			}
			if (substr($r, 0, 3) === ';;;')
			{
				$this->checkSystemInfo($r);
				continue;
			}

			$this->addCurrentPartValue($r);
		}
	}

	public function show()
	{
		// --- info
		echo "### info\n";
		foreach ($this->info as $k => $v)
			echo " ".$k.": ".$v."\n";
		echo "\n";

		// -- sw
		echo json_encode($this->dataOriginal['sw'])."\n\n";

		foreach ($this->dataOriginal['sw'] as $part)
		{
			echo "### '{$part['title']}'; user: `{$part['userId']}`; osInfo: ".intval($part['isOSInfo'])."; {$part['checkSum']}"."\n";
			echo $part['json']."\n";

			echo "\n";
		}
	}

	public function saveAll()
	{
		$ipAddress = (isset($_SERVER ['REMOTE_ADDR'])) ? $_SERVER ['REMOTE_ADDR'] : '';

		$deviceRecData = NULL;

		if (!$this->checkReUpload())
			return;

		if (isset($this->info['deviceUid']))
			$deviceRecData = $this->db()->query('SELECT ndx FROM [mac_lan_devices] WHERE [uid] = %s', $this->info['deviceUid'])->fetch();
		elseif (isset($this->info['deviceNdx']))
			$deviceRecData = $this->db()->query('SELECT ndx FROM [mac_lan_devices] WHERE [ndx] = %i', $this->info['deviceNdx'])->fetch();
		if (!$deviceRecData)
		{
			if (isset($this->info['deviceUid']))
				error_log("### NO DEVICE FOR deviceUid `{$this->info['deviceUid']}`");
			elseif (isset($this->info['deviceNdx']))
				error_log("### NO DEVICE FOR deviceNdx `{$this->info['deviceNdx']}`");
			return;
		}

		$deviceNdx = $deviceRecData['ndx'];

		foreach ($this->dataOriginal['sw'] as $part)
		{
			$osFamily = 0; // windows
			if ($part['values']['_saOS'] === 'linux')
				$osFamily = 2; // linux
			elseif ($part['values']['_saOS'] === 'mikrotik')
				$osFamily = 3; // mikrotik
			elseif ($part['values']['_saOS'] === 'edgecore')
				$osFamily = 4; // edgecore
			elseif ($part['values']['_saOS'] === 'nas')
				$osFamily = 5; // NAS
			elseif ($part['values']['_saOS'] === 'iotbox')
				$osFamily = 100; // IoTBox
			elseif ($part['values']['_saOS'] === 'ipcams')
				$osFamily = 1000; // ipcams

			$isOSInfo = intval($part['isOSInfo']);

			if ($isOSInfo)
				$exist = $this->db()->query('SELECT * FROM [mac_swlan_infoQueue] WHERE [device] = %i', $deviceNdx,
					' AND [docState] = %i', 1000, ' AND [osInfo] = %i', 1)->fetch();
			else
				$exist = $this->db()->query('SELECT * FROM [mac_swlan_infoQueue] WHERE [device] = %i', $deviceNdx,
					' AND [docState] != %i', 9800, ' AND [checksumOriginal] = %s', $part['checkSum'],
					' AND [osUserId] = %s', $part['userId'], ' AND [osInfo] = %i', 0)->fetch();

			if ($exist)
			{
				$update = [
					'osFamily' => $osFamily,
					'dateSameAsOriginal' => new \DateTime(),
					'cntSameAsOriginal' => $exist['cntSameAsOriginal'] + 1,
					'ipAddressSameAsOriginal' => $ipAddress,
				];
				if ($isOSInfo)
				{
					$update['dataOriginal'] = $part['json'];
					$update['checksumOriginal'] = $part['checkSum'];
				}

				$this->db()->query('UPDATE [mac_swlan_infoQueue] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);
			}
			else
			{
				$now = new \DateTime();
				$newItem = [
					'device' => $deviceNdx,
					'deviceUid' => isset($this->info['deviceUid']) ? $this->info['deviceUid'] : '',
					'title' => $part['title'],
					'osFamily' => $osFamily, 'osInfo' => $isOSInfo, 'osUserId' => $part['userId'],
					'dataOriginal' => $part['json'], 'checksumOriginal' => $part['checkSum'],
					'dateCreate' => $now, 'ipAddress' => $ipAddress,
					'dateSameAsOriginal' => $now, 'ipAddressSameAsOriginal' => $ipAddress, 'cntSameAsOriginal' => 0,
					'docState' => 1000, 'docStateMain' => 0,
				];

				$this->db()->query('INSERT INTO [mac_swlan_infoQueue] ', $newItem);
			}
		}

		// -- watch dog
		/** @var \mac\lan\TableWatchdogs $tableWatchdogs */
		$tableWatchdogs = $this->app()->table('mac.lan.watchdogs');
		$tableWatchdogs->touchFromDevice('agent-installed-sw', $deviceNdx, strchr($this->tmpFileName, '/tmp/'));
	}

	function checkReUpload()
	{
		if ($this->reUploadFrom === '')
			return TRUE;
		$reUploadFrom = $this->app()->cfgItem('mac.lan.reupload.from.'.$this->reUploadFrom, NULL);
		if (!$reUploadFrom)
			return FALSE;

		if (isset($this->info['deviceUid']))
		{
			if (!isset($reUploadFrom['deviceUid'][$this->info['deviceUid']]))
				return FALSE;
			$this->info['deviceUid'] = $reUploadFrom['deviceUid'][$this->info['deviceUid']];
		}

		if (isset($this->info['deviceNdx']))
		{
			if (!isset($reUploadFrom['deviceNdx'][$this->info['deviceNdx']]))
				return FALSE;
			$this->info['deviceNdx'] = $reUploadFrom['deviceNdx'][$this->info['deviceNdx']];
		}

		return TRUE;
	}

	public function parse()
	{
		$this->createDataOriginal();
	}
}
