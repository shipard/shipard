<?php

namespace mac\swlan;
use e10\utils;


/**
 * Class ModuleServices
 * @package mac\swlan
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
	}

	function infoQueueAdd()
	{
		$fileName = $this->app->arg('file-name');
		if (!$fileName)
			return $this->app->err('arg `--file-name` is missing');

		$srcText = file_get_contents($fileName);
		if (!$srcText)
			return $this->app->err('file `'.$fileName.'` not found');

		$iqp = new \mac\swlan\libs\InfoQueueParser($this->app);
		$iqp->setSrcText($srcText);
		$iqp->parse();
		$iqp->show();
	}

	function infoQueueSanitize()
	{
		$iqp = new \mac\swlan\libs\InfoQueueSanitizer($this->app);
		$iqp->init();
		$iqp->run();
	}

	function infoQueueTester()
	{
		$iqt = new \mac\swlan\libs\InfoQueueTester($this->app);
		$iqt->init();
		$iqt->run();
	}

	function infoQueueSWLoader()
	{
		$iqt = new \mac\swlan\libs\InfoQueueSWLoader($this->app);
		$iqt->init();
		$iqt->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'info-queue-add': return $this->infoQueueAdd();
			case 'info-queue-sanitize': return $this->infoQueueSanitize();
			case 'info-queue-tester': return $this->infoQueueTester();
			case 'info-queue-sw-loader': return $this->infoQueueSWLoader();
		}

		parent::onCliAction($actionId);
	}

	public function onCronEver ()
	{
		$this->infoQueueSanitize();
	}

	public function onCronHourly ()
	{
		$this->infoQueueTester();
		$this->infoQueueSWLoader();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'ever': $this->onCronEver(); break;
			case 'hourly': $this->onCronHourly(); break;
		}
		return TRUE;
	}
}
