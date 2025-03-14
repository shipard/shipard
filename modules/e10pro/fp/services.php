<?php

namespace e10pro\fp;

/**
 * class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function sendDownloadInvites()
	{
		$e = new \e10pro\fp\libs\apps\DownloadInvitesEngine($this->app());
		$e->sendAll();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'send-download-invites': return $this->sendDownloadInvites();
		}

		parent::onCliAction($actionId);
	}

	public function onCronEver ()
	{
		$this->sendDownloadInvites();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'ever': $this->onCronEver(); break;
		}
		return TRUE;
	}
}
