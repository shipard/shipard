<?php

namespace mac\iot\dc;

use e10\utils, e10\json;


/**
 * Class ThingCfg
 * @package mac\iot\dc
 */
class ThingCfg extends \e10\DocumentCard
{
	public function createContentBody ()
	{
		$tabs = [];

		$this->createContentBody_Thing($tabs);

		// -- final content
		$this->addContent('body', ['tabsId' => 'mainTabs', 'selectedTab' => '0', 'tabs' => $tabs]);
	}

	function createContentBody_Thing(&$tabs)
	{
		$q[] = 'SELECT * FROM [mac_iot_thingsCfg]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [thing] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [ndx]');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$class = ($cnt) ? 'e10-error' : '';

			// -- live
			$content = [['pane' => 'e10-pane e10-pane-table','type' => 'text', 'subtype' => 'code', 'text' => $r['thingCfgData'],]];
			$title = ['text' => 'Nastavení', 'icon' => 'icon-wrench', 'class' => $class];
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
