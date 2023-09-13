<?php

namespace mac\lan\dc;

use e10\utils, e10\json;


/**
 * Class DocumentCardDeviceScripts
 * @package mac\lan\dc
 */
class DocumentCardDeviceScripts extends \e10\DocumentCard
{
	var $scriptGenerator = NULL;

	public function createContentBody ()
	{
		$tabs = [];

		if ($this->recData['nodeSupport'])
		{ // -- node server
			$this->createContentBody_NodeServer($tabs);
		}
		elseif ($this->recData['deviceKind'] === 75)
		{ // -- IoT Box
			$this->createContentBody_IotBox($tabs);
		}
		else
		{
			// -- load
			$existedScripts = $this->db()->query('SELECT * FROM [mac_lan_devicesCfgScripts] WHERE [device] = %i', $this->recData['ndx'])->fetch();

			// -- init / reset script
			$initContent = [];
			$this->scriptGenerator = new \mac\lan\libs\LanCfgDeviceScriptGenerator($this->app());
			$this->scriptGenerator->init();
			$this->scriptGenerator->setDevice($this->recData['ndx'], NULL, TRUE);
			$this->scriptGenerator->addToContent($initContent);
			$tabs[] = ['title' => ['text' => 'Inicializace', 'icon' => 'system/iconCogs'], 'content' => $initContent];

			// -- generated scripts - running
			$this->addScriptTab($tabs, ['text' => 'V zařízení', 'icon' => 'system/iconCheckSquare'], $existedScripts, 'running');
			$this->addScriptTab($tabs, ['text' => 'Nastavuje se', 'icon' => 'iconMagic'], $existedScripts, 'new');
			$this->addScriptTab($tabs, ['text' => 'K nastavení', 'icon' => 'system/actionSettings'], $existedScripts, 'live');

			// -- upgrade
			if ($this->scriptGenerator->dsg && $this->scriptGenerator->dsg->scriptUpgrade !== '')
			{
				$contentUpgrade = [[
					'pane' => 'e10-pane e10-pane-table',
					'type' => 'text', 'subtype' => 'code', 'text' => $this->scriptGenerator->dsg->scriptUpgrade,
				]];
				$tabs[] = ['title' => ['text' => 'Upgrade', 'icon' => 'system/iconCogs'], 'content' => $contentUpgrade];
			}
		}

		$this->createContentBody_RealTimeSNMP($tabs);

		// -- final content
		$this->addContent('body', ['tabsId' => 'mainTabs', 'selectedTab' => '0', 'tabs' => $tabs]);
	}

	function addScriptTab (&$tabs, $tabTitle, $scriptsData, $part)
	{
		$content = [];

		if (!$scriptsData || !$scriptsData[$part.'Text'] || $scriptsData[$part.'Text'] === '')
		{
			if ($part === 'running')
				$msg = ['text' => 'Nastavení není zatím dostupné', 'class' => 'h2'];
			else
				$msg = ['text' => 'Žádný obsah', 'class' => 'h2'];

			$content[] = ['pane' => 'e10-pane e10-pane-table', 'type' => 'line',
				'line' => $msg
			];

			$title = $tabTitle;
			$tabs[] = ['title' => $title, 'content' => $content];
			return;
		}

		$info = [];
		if ($scriptsData[$part.'Timestamp'])
			$info[] = ['text' => utils::datef($scriptsData[$part.'Timestamp'], '%d, %T'), 'icon' => 'system/iconCalendar', 'class' => 'label label-default'];
		$info[] = ['text' => utils::memf(strlen($scriptsData[$part.'Text'])), 'icon' => 'system/iconPencil', 'class' => 'label label-default'];
		if ($scriptsData[$part.'Ver'] !== '')
			$info[] = ['text' => '#'.$scriptsData[$part.'Ver'], 'icon' => 'system/iconPencil', 'class' => 'label label-default'];

		$content[] = [
			'pane' => 'padd5 e10-pane e10-pane-table',
			'type' => 'line', 'line' => $info
		];

		$content[] = [
			'pane' => 'e10-pane e10-pane-table',
			'type' => 'text', 'subtype' => 'code', 'text' => $scriptsData[$part.'Text'],
		];

		$content[] = [
			'pane' => 'e10-pane e10-pane-table',
			'type' => 'text', 'subtype' => 'code', 'text' => $scriptsData[$part.'Data'],
			'paneTitle' => ['text' => 'Datová struktura', 'class' => 'subtitle']
		];

		$title = $tabTitle;
		$tabs[] = ['title' => $title, 'content' => $content];
	}

	function createContentBody_NodeServer(&$tabs)
	{
		$q[] = 'SELECT * FROM [mac_lan_devicesCfgNodes]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [device] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [ndx]');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$class = ($cnt) ? 'e10-error' : '';

			// -- running
			$content = [['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => $r['runningData'],]];
			$title = ['text' => 'V zařízení', 'icon' => 'system/iconCheckSquare', 'class' => $class];
			$tabs[] = ['title' => $title, 'content' => $content];

			// -- new
			$content = [['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => $r['newData'],]];
			$title = ['text' => 'Nastavuje se', 'icon' => 'iconMagic', 'class' => $class];
			$tabs[] = ['title' => $title, 'content' => $content];

			// -- live
			$content = [['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => $r['liveData'],]];
			$title = ['text' => 'K nastavení', 'icon' => 'system/actionSettings', 'class' => $class];
			$tabs[] = ['title' => $title, 'content' => $content];

			// -- node linux
			$this->createContentBody_NodeServerLinux($tabs);

			// -- node linux SNMP all
			$this->createContentBody_NodeServerSNMP($tabs);

			$cnt++;
		}
	}

	function createContentBody_IotBox(&$tabs)
	{
		$q[] = 'SELECT * FROM [mac_lan_devicesCfgIoTBoxes]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [device] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [ndx]');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$class = ($cnt) ? 'e10-error' : '';

			// -- live
			$content = [['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => $r['iotBoxCfgData'],]];
			$title = ['text' => 'IotBox', 'icon' => 'system/actionSettings', 'class' => $class];
			$tabs[] = ['title' => $title, 'content' => $content];

			$cnt++;
		}
	}

	function createContentBody_RealTimeSNMP(&$tabs)
	{
		$deviceInfo = new \mac\lan\libs\DeviceInfo($this->app());
		$deviceInfo->setDevice($this->recData['ndx']);

		if (!isset($deviceInfo->macDeviceSubTypeCfg['snmpTemplateRealtime']))
			return;

		$template = new \mac\lan\libs\DeviceMonitoringTemplate($this->app());
		$template->data['device'] = $deviceInfo->info;

		$template->loadTemplate ($deviceInfo->macDeviceSubTypeCfg['snmpTemplateRealtime']);

		$c = $template->renderTemplate();
		$data = json_decode($c, TRUE);
		$dataStr = json::lint($data);

		$content = [
			['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => $dataStr, 'paneTitle' => ['text' => 'Realtime skript', 'class' => 'h2 subtitle']],
			['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => $template->templateCode(), 'paneTitle' => ['text' => 'Realtime šablona', 'class' => 'h2']],
		];
		$title = ['text' => 'SNMP', 'icon' => 'system/iconSitemap'];
		$tabs[] = ['title' => $title, 'content' => $content];

	}

	function createContentBody_NodeServerLinux(&$tabs)
	{
		$content = [];

		// -- install
		$dsUrl = 'https://'.$_SERVER['HTTP_HOST'].$this->app()->dsRoot.'/';

		$apiKey = '';
		$lanRecData = $this->db()->query('SELECT * FROM [mac_lan_lans] WHERE [ndx] = %i', $this->recData['lan'])->fetch();
		if ($lanRecData && $lanRecData['robotUser'])
		{
			$q[] = 'SELECT * FROM e10_persons_userspasswords WHERE [pwType] = 1';
			array_push($q, 'AND [person] = %i', $lanRecData['robotUser']);
			$apiKeyData = $this->db()->query($q)->fetch();
			if ($apiKeyData)
				$apiKey = $apiKeyData['emailHash'];
		}

		$codeInstall = "wget https://download.shipard.org/shipard-node/server-app-2/shipard-node-install-devel-debian.cmd\n";
		$codeInstall .= "sh shipard-node-install-devel-debian.cmd\n";
		$codeInstall .= "shipard-node cfg-init --server-id=\"{$this->recData['ndx']}\" --ds-url=\"$dsUrl\" --api-key=\"{$apiKey}\"\n";
		$codeInstall .= "\n\n";
		$content[] = ['pane' => 'e10-pane e10-pane-table', 'paneTitle' => ['text' => 'Instalace Shipard Node', 'class' => 'h2 mb2 block'], 'type' => 'text', 'subtype' => 'code', 'text' => $codeInstall];

		$codeInstall = "wget https://download.shipard.org/shipard-agent/linux/shipard-agent-install-stable-debian.cmd\n";
		$codeInstall .= "sh shipard-agent-install-stable-debian.cmd\n";
		$codeInstall .= "\n";
		$codeInstall .= "printf '{\\n\\t\"dsUrl\": \"{$dsUrl}\",\\n\\t\"deviceNdx\": {$this->recData['ndx']},\\n\\t\"deviceUid\": \"{$this->recData['uid']}\"\\n}\\n' > /etc/shipard-agent/config2.json\n";
		$codeInstall .= "\n";
		$codeInstall .= "shipard-agent host-check\n";
		$codeInstall .= "shipard-agent do-it\n";
		$codeInstall .= "\n\n";
		$content[] = ['pane' => 'e10-pane e10-pane-table', 'paneTitle' => ['text' => 'Instalace Shipard Agent', 'class' => 'h2 mb2 block'], 'type' => 'text', 'subtype' => 'code', 'text' => $codeInstall];

		// -- tab
		$title = ['text' => 'Linux', 'icon' => 'icon-wrench', 'class' => ''];
		$tabs[] = ['title' => $title, 'content' => $content];
	}

	function createContentBody_NodeServerSNMP(&$tabs)
	{
		$content = [];

		$snmpEngine = new \mac\lan\libs\GetLanMonitoringSnmp($this->app());
		$snmpEngine->serverNdx = $this->recData['ndx'];
		$snmpEngine->run();

		$codeSNMP = '';
		$codeSNMP .= $snmpEngine->result['netdataCfgFile'];
		$content[] = ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code', 'text' => $codeSNMP];

		// -- tab
		$title = ['text' => 'SNMP', 'icon' => 'icon-wrench', 'class' => ''];
		$tabs[] = ['title' => $title, 'content' => $content];
	}

	public function createContent ()
	{
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
