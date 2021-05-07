<?php

namespace mac\lan\dc;

use e10\utils, e10\json;


/**
 * Class DocumentCardMacsOnPorts
 * @package mac\lan\dc
 */
class DocumentCardMacsOnPorts extends \e10\DocumentCard
{
	public function createContentBody ()
	{
		// -- data
		//$this->addContent('body', ['type' => 'text', 'subtype' => 'auto', 'text' => $this->recData ['macs']]);

		$macs = json_decode($this->recData ['macs'], TRUE);
		if (!$macs)
			return;

		$mu = new \mac\lan\libs\MacsUtils($this->app());
		$info = $mu->loadMacs($macs);

		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $info['table'], 'header' => $info['header']]);

		$connectHints = $mu->checkConnectToHint($this->recData);
		if ($connectHints)
		{
			$this->addContent('body', [
				'pane' => 'e10-pane pa1', 'paneTitle' => $connectHints['title'],
				'type' => 'line', 'line' => $connectHints['info'],
			]);
		}
	}

	public function createContent ()
	{
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
