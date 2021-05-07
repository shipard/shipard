<?php

namespace mac\swlan\dc;


/**
 * Class SW
 * @package mac\swlan\dc
 */
class SW extends \e10\DocumentCard
{
	/** @var \mac\swlan\libs\SWInfoLan */
	var $swInfo;

	/** @var \mac\sw\TablePublishers */
	var $tablePublishers;

	function addIntro()
	{
		$t = [];

		// -- name
		$t[] = [
			'c1' => 'Název',
			'c2' => $this->recData['fullName']
		];

		// -- swClass
		$swClass = $this->app()->cfgItem('mac.swcore.swClass.'.$this->recData['swClass']);
		$t[] = [
			'c1' => 'Druh',
			'c2' => ['text' => $swClass['fn'], 'icon' => $swClass['icon']]
		];

		// -- publisher
		if ($this->recData['publisher'])
		{
			$publisher = $this->tablePublishers->loadItem($this->recData['publisher']);
			$t[] = [
				'c1' => 'Vydavatel',
				'c2' => ['text' => $publisher['fullName']],
			];
		}

		$h = ['c1' => 'c1', 'c2' => 'c2'];
		$this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table e10-pane-top', 'type' => 'table', 'table' => $t, 'header' => $h,
			'params' => ['forceTableClass' => 'properties fullWidth', 'hideHeader' => 1],
		]);
	}

	function addDevices()
	{
		$header = [
			'#' => '#', 'device' => 'Zařízení', 'version' => 'Verze',
			//'date' => 'Datum'
		];

		$this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table', 'table' => $this->swInfo->onDevices, 'header' => $header,
			'paneTitle' => ['text' => 'Instalováno na:', 'class' => 'h2'],
		]);
	}

	public function createContentBody ()
	{
		$this->addIntro();
		$this->addDevices();
	}

	public function createContent ()
	{
		$this->tablePublishers = $this->app()->table('mac.sw.publishers');

		$this->swInfo = new \mac\swlan\libs\SWInfoLan($this->app());
		$this->swInfo->init();
		$this->swInfo->setSW($this->recData['ndx']);
		$this->swInfo->run();

		$this->createContentBody ();
	}
}
