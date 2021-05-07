<?php

namespace e10pro\bume;


/**
 * Class ModuleServices
 * @package e10pro\bume
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function sendBulkEmails()
	{
		$e = new \lib\wkf\SendBulkEmailEngine($this->app);
		$e->sendAllBulkEmails();
	}

	public function onCronEver()
	{
		$this->sendBulkEmails();
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
