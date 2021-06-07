<?php

namespace mac\lan\libs;

use e10\utils;


/**
 * Class ReportShipardAgentInstall
 * @package mac\lan\libs
 */
class ReportShipardAgentInstall extends \mac\lan\Report
{
	var $data = [];
	var $files = [];
	var $lanNdx = 1;

	function init ()
	{
		parent::init();
	}

	function createContent ()
	{
		$this->loadData();

		switch ($this->subReportId)
		{
			case '':
			case 'all': $this->createContent_All(); break;
		}

		$this->setInfo('title', 'Instalace ShipardAgent');
	}

	function createContent_All ()
	{
		$this->addContent (['type' => 'text', 'text' => $this->files['txt']]);
	}

	public function loadData ()
	{
		$now = new \DateTime();

		$this->files['txt'] = '';

		$this->files['txt'] .= '; Stažení instalačního souboru: https://download.shipard.org/shipard-agent/shipard-agent.zip'."\n\n";

		$this->files['txt'] .= '; Příkaz pro instalaci (powershell s právy administrátora)'."\n";
		$this->files['txt'] .= "Register-ScheduledTask -xml (Get-Content 'C:\\shipard-agent\\bin\\shipard-agent-task.xml' | Out-String) -TaskName \"shipard_agent\" -TaskPath \"\\shipard_agent\\\" -User SYSTEM –Force";
		$this->files['txt'] .= "\n\n\n";
		$this->files['txt'] .= "; Jednotlivé config.ini soubory; ".$now->format('Y-m-d H:i:s')."\n\n";

		$q[] = 'SELECT [devices].* ';
		array_push ($q, ' FROM [mac_lan_devices] AS [devices]');
		array_push ($q, ' WHERE devices.docStateMain < 3');
		array_push ($q, ' AND [devices].lan = %i', $this->reportParams ['lan']['value']);
		array_push ($q, ' AND (',
			'[devices].deviceKind IN %in', [1, 2],
			'OR ([devices].deviceKind = %i', 7, ' AND [devices].macDeviceType = %s', 'server-windows', ')',
			')');
		array_push ($q, ' ORDER BY [devices].id, devices.fullName');
		$rows = $this->app->db()->query($q);

		foreach ($rows as $r)
		{
			$this->files['txt'] .= "\n\n";

			$this->files['txt'] .="; ".$r['id']." - ".$r['fullName']."\n";
			$this->files['txt'] .= "dsUrl=https://".$_SERVER['HTTP_HOST'].$this->app()->urlRoot."\n";
			$this->files['txt'] .= "deviceNdx=".$r['ndx']."\n";
			$this->files['txt'] .= "deviceUid=".$r['uid']."\n";
		}

		$this->files['txt'] .= "\n\n";
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = ['text' => 'Textový soubor (.txt)', 'icon' => 'icon-file-text-o', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'text'];
	}

	public function saveReportAs ()
	{
		$this->loadData();

		$fileName = utils::tmpFileName('txt');
		file_put_contents($fileName, $this->files['txt']);

		$this->fullFileName = $fileName;
		$this->saveFileName = $this->saveAsFileName ($this->format);
		$this->mimeType = 'text/plain';
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'all', 'icon' => 'detailAll', 'title' => 'Vše'];

		return $d;
	}

	public function saveAsFileName ($type)
	{
		return 'shipard-agent-install.txt';
	}
}
