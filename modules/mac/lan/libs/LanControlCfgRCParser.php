<?php

namespace mac\lan\libs;

use e10\Utility, \e10\json;


/**
 * Class LanControlCfgRCParser
 * @package mac\lan\libs
 */
class LanControlCfgRCParser extends Utility
{
	/** @var \e10\DbTable */
	var $tableDevices;
	var $deviceNdx = 0;
	var $deviceRecData = NULL;

	var $srcScript = '';

	/** @var \mac\lan\libs\cfgScripts\parser\CoreCfgScriptParser */
	var $cfgParser = NULL;

	/** @var \mac\lan\libs\cfgScripts\CoreCfgScript */
	var $dsg = NULL;

	public function setDevice($deviceNdx)
	{
		$this->tableDevices = $this->app()->table ('mac.lan.devices');

		$this->deviceNdx = $deviceNdx;
		$this->deviceRecData = $this->tableDevices->loadItem($deviceNdx);
		if (!$this->deviceRecData)
		{
			error_log ("deviceNdx `{$this->lanNdx}` not exist...");
			return;
		}

		$sgClassId = $this->tableDevices->sgClassId($this->deviceRecData);

		if ($sgClassId !== '')
		{
			$this->dsg = $this->app()->createObject ($sgClassId);
			if ($this->dsg)
			{
				$this->dsg->setDevice($this->deviceRecData, NULL);
				$this->cfgParser = $this->dsg->cfgParser();
			}
		}
	}

	public function saveTo ()
	{
		$dstColumn = 'runningData';

		$exist = $this->db()->query('SELECT * FROM [mac_lan_devicesCfgScripts] WHERE [device] = %i', $this->deviceNdx)->fetch();
		if (!$exist)
			return FALSE;

		$update = [$dstColumn => json::lint($this->cfgParser->parsedData)];

		$update['runningVer'] = sha1($exist['runningText'].$update['runningData']);
		$update['runningTimestamp'] = new \DateTime();

		$this->db()->query('UPDATE [mac_lan_devicesCfgScripts] SET ', $update, ' WHERE ndx = %i', $exist['ndx']);

		return TRUE;
	}
}