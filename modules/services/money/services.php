<?php

namespace services\money;
use e10\utils;


/**
 * Class ModuleServices
 * @package services\money
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function ExchangeRateListsDownload ()
	{
		$e = new \services\money\libs\ExchangeRateListsDownloader($this->app);

		$dateFrom = $this->app->arg('date-from');
		if ($dateFrom)
			$e->setDateFrom($dateFrom);

		$dateTo = $this->app->arg('date-to');
		if ($dateTo)
			$e->setDateTo($dateTo);

		if ($dateTo || $dateFrom)
			$e->interactive = 1;

		$e->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'exchange-rate-lists-download': return $this->ExchangeRateListsDownload();
		}

		return parent::onCliAction($actionId);
	}

	public function onCronEver ()
	{
		$this->ExchangeRateListsDownload();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case	'ever':   $this->onCronEver (); break;
		}
		return TRUE;
	}
}
