<?php

namespace mac\lan\dc;

use e10\utils, e10\json;


/**
 * Class DocumentCardMac
 * @package mac\lan\dc
 */
class DocumentCardMac extends \e10\DocumentCard
{
	public function createContentBody ()
	{
		// -- data
		//$this->addContent('body', ['type' => 'text', 'subtype' => 'auto', 'text' => $this->recData ['ports']]);

		$devicesPorts = json_decode($this->recData ['ports'], TRUE);
		if (!$devicesPorts)
			return;

		$mu = new \mac\lan\libs\MacsUtils($this->app());
		$info = $mu->loadDevicesPorts($devicesPorts);

		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $info['table'], 'header' => $info['header']]);
	}

	public function createContent ()
	{
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
