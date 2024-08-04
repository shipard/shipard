<?php

namespace mac\lan\libs;

use e10\Utility, e10\json;


/**
 * Class LanCfgCreator
 * @package mac\lan\libs
 */
class LanCfgDeviceScriptGenerator extends Utility
{
	/** @var \mac\lan\TableLans */
	var $tableLans;
	/** @var \mac\lan\TableDevices */
	var $tableDevices;

	var $deviceNdx = 0;
	var $deviceRecData = NULL;
	var $lanNdx = 0;

	var $lanCfgCreator = NULL;

	var $sgClassId = '';
	/** @var \mac\lan\libs\cfgScripts\CoreCfgScript */
	var $dsg = NULL;


	public function init()
	{
		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableDevices = $this->app()->table('mac.lan.devices');
	}

	public function setDevice ($deviceNdx, $lanCfg = NULL, $initMode = FALSE)
	{
		$this->deviceNdx = $deviceNdx;
		$this->deviceRecData = $this->tableDevices->loadItem($deviceNdx);
		if (!$this->deviceRecData)
		{
			error_log ("deviceNdx `{$deviceNdx}` not exist...");
			return;
		}


		$this->lanNdx = $this->deviceRecData['lan'];

		if ($lanCfg)
			$this->lanCfgCreator = $lanCfg;
		else
		{
			$this->lanCfgCreator = new \mac\lan\libs\LanCfgCreator($this->app());
			$this->lanCfgCreator->init();
			$this->lanCfgCreator->setLan($this->lanNdx);
			$this->lanCfgCreator->load();
		}

		$this->sgClassId = $this->tableDevices->sgClassId($this->deviceRecData)	;
		if ($this->sgClassId !== '')
		{
			$this->dsg = $this->app()->createObject ($this->sgClassId);
			if ($this->dsg)
			{
				$this->dsg->setDevice($this->deviceRecData, $this->lanCfgCreator->cfg);
				$this->dsg->createScript($initMode);

				if ($this->dsg->messages())
					$this->appendMessages($this->dsg->messages());
			}
		}
	}

	public function addToContent(&$content)
	{
		if ($this->lanCfgCreator->messages())
		{
			$msgs = [];

			if ($this->messages())
				foreach ($this->messages() as $msg)
					$msgs[] = ['text' => $msg['text'], 'class' => 'e10-error block', 'icon' => 'system/iconWarning'];

			if ($this->lanCfgCreator->messages())
				foreach ($this->lanCfgCreator->messages() as $msg)
					$msgs[] = ['text' => $msg['text'], 'class' => 'block'];

			$content[] = ['pane' => 'e10-pane e10-pane-table', 'type' => 'line',
				'line' => $msgs,
				'paneTitle' => ['text' => 'Nastavení sítě obsahuje nesrovnalosti', 'class' => 'h1 e10-error', 'icon' => 'system/iconWarning']
			];
		}

		if ($this->dsg)
		{
			$content[] = [
				'pane' => 'e10-pane e10-pane-table',
				'type' => 'text', 'subtype' => 'code', 'text' => $this->dsg->initScriptFinalized(),
			];

			if (count($this->dsg->scripsUtils))
			{
				foreach ($this->dsg->scripsUtils as $scu)
				{
					$content[] = [
						'pane' => 'e10-pane e10-pane-table',
						'type' => 'text', 'subtype' => 'code', 'text' => $scu['script'],
						'paneTitle' => ['text' => $scu['title'], 'class' => 'subtitle'],
					];
				}
			}
		}
		else
		{
			$content[] = ['pane' => 'e10-pane e10-pane-table', 'type' => 'line',
				'line' => ['text' => 'Skript není k dispozici', 'class' => 'h2']
			];
		}
	}

	public function addToDeviceCfgScripts(&$update)
	{
		if (!$this->dsg)
		{
			return;
		}

		$liveData = json::lint($this->dsg->cfgData);

		$update['liveText'] = $this->dsg->script;
		$update['liveData'] = $liveData;

		$update['liveVer'] = sha1($this->dsg->script.$liveData);
	}
}