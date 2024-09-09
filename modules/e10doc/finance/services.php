<?php

namespace e10doc\finance;

use E10\utils;


/**
 * Class ModuleServices
 * @package E10Pro\Purchase
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function downloadBankStatements($inboxOnly = FALSE)
	{
		if (!$this->app->production())
			return;

		$now = new \DateTime ();
		$hour = intval($now->format('H'));

		$bankAccounts = $this->app->cfgItem ('e10doc.bankAccounts', []);
		foreach ($bankAccounts as $ba)
		{
			if (!isset($ba['ds']) || $ba['ds'] === '' || $ba['ds'] === 'none')
				continue;

			$ebankingCfg = $this->app->cfgItem ('ebanking.downloads.'.$ba['ds'], FALSE);
			if ($ebankingCfg === FALSE || !isset($ebankingCfg['class']))
				continue;

			if($inboxOnly && !isset($ebankingCfg['inbox']))
				continue;

			$engine = $this->app->createObject($ebankingCfg['class']);
			if (!$engine)
				continue;
			$engine->setBankAccount ($ba);
			$engine->init ();
			$engine->run ();
		}
	}

	public function downloadBankTransactions($force = 0)
	{
		if (!$force && !$this->app->production())
			return;

		$bankAccounts = $this->app->cfgItem ('e10doc.bankAccounts', []);
		foreach ($bankAccounts as $ba)
		{
			if (!isset($ba['dt']) || $ba['dt'] === '' || $ba['dt'] === 'none')
				continue;

			$ebankingCfg = $this->app->cfgItem ('ebanking.transactions.'.$ba['dt'], FALSE);
			if ($ebankingCfg === FALSE || !isset($ebankingCfg['class']))
				continue;

			$engine = $this->app->createObject($ebankingCfg['class']);
			if (!$engine)
				continue;
			$engine->setBankAccount ($ba);
			$engine->init ();
			$engine->run ();
		}
	}

	public function onCronHourly ()
	{
		$this->downloadBankStatements();
	}

	public function onCronEver ()
	{
		$this->downloadBankTransactions();
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

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'download-inbox-bank-statements': $this->downloadBankStatements(TRUE); return TRUE;
			case 'download-all-bank-statements': $this->downloadBankStatements(); return TRUE;
			case 'download-all-bank-transactions': $this->downloadBankTransactions(1); return TRUE;
		}

		parent::onCliAction($actionId);
	}
}
