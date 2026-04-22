<?php

namespace integrations\govbox;


/**
 * Class ModuleServices
 * @package integrations\core
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function downloadSentMessages()
	{
		$dsme = new \integrations\govbox\libs\DownloadSentMessages($this->app);
		$dsme->run();

		return TRUE;
	}
	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'download-sent-messagess': return $this->downloadSentMessages();
		}

		parent::onCliAction($actionId);
	}
}
