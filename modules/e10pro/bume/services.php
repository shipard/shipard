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

	protected function sendBulkPost()
	{
		$postNdx = intval($this->app->arg('postNdx'));
		if (!$postNdx)
			return $this->app->err('arg `--postNdx` is missing or wrong');

		$params = ['actionTable' => 'e10pro.bume.bulkEmails', 'actionPK' => $postNdx];
		$sendAction = new \lib\wkf\SendBulkEmailAction($this->app());
		$sendAction->setParams($params);
		$sendAction->init();
		$sendAction->run();

		return TRUE;
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

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'send-bulk-post': return $this->sendBulkPost();
		}

		parent::onCliAction($actionId);
	}
}
