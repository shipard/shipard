<?php

namespace mac\iot\dc;

use e10\utils, \Shipard\Utils\Json;


/**
 * Class DCIotDeviceConfig
 */
class DCIotDeviceConfig extends \e10\DocumentCard
{
	var $scriptGenerator = NULL;

	public function createContentBody ()
	{
		$tabs = [];

		$this->createContentBody_IotBox($tabs);

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

	function createContentBody_IotBox(&$tabs)
	{
		
		$q[] = 'SELECT * FROM [mac_iot_devicesCfg]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [iotDevice] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [ndx]');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$class = ($cnt) ? 'e10-error' : '';

			// -- live
			$content = [];
			$content[] = ['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => $r['cfgData'],];
			$content[] = ['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => $r['deviceInfoData'],];


			$xx = new \mac\iot\libs\IotEngineCfgCreator($this->app());
			$xx->init();
			$xx->run();
			$content[] = ['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => Json::lint($xx->cfg),];


			$title = ['text' => 'IotBox', 'icon' => 'system/actionSettings', 'class' => $class];
			$tabs[] = ['title' => $title, 'content' => $content];

			$cnt++;
		}
	}

	public function createContent ()
	{
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
