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

	protected function syncPersonsPull()
	{
		$listNdx = intval($this->app->arg('listNdx'));
		if (!$listNdx)
			return $this->app->err('arg `--listNdx` is missing or wrong');

		$url = $this->app->arg('url');
		if (!$url || $url == '')
			return $this->app->err('arg `--url` is missing or wrong');

		$apiKey = $this->app->arg('apiKey');
		if (!$apiKey || $apiKey == '')
			return $this->app->err('arg `--apiKey` is missing or wrong');

		$addLabels = $this->app->arg('addLabels');

		$e = new \e10pro\bume\libs\PersonsSyncPullEngine($this->app());
		$e->contactsListNdx = $listNdx;
		$e->url = $url;
		$e->apiKey = $apiKey;

		if ($addLabels && $addLabels != '')
			$e->setAddLabels($addLabels);

		$e->run();

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
			case 'sync-persons-pull': return $this->syncPersonsPull();
		}

		parent::onCliAction($actionId);
	}
}
