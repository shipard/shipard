<?php

namespace e10pro\loyp;


/**
 * class ModuleServices
 */
class ModuleServices extends \e10\cli\ModuleServices
{
	function recalcPoints()
	{
		$dpr = new \e10pro\loyp\libs\DocsPointsRecalc($this->app);
		$dpr->run();
	}

	function reaccountingPoints()
	{
		$loyp = intval($this->app->arg('loyp'));
		if (!$loyp)
		{
			echo "ERROR: param `--loyp=` not found\n";
			return;
		}

		$dpr = new \e10pro\loyp\libs\ReAccountingPointsEngine($this->app);
		$dpr->init();
		$dpr->loypNdx = $loyp;
		$dpr->run();
	}

	function balanceCleanup()
	{
		$docNumber = $this->app->arg('docNumber');
		if (!$docNumber)
		{
			echo "ERROR: param `--docNumber=` not found\n";
			return;
		}

		$bce = new \e10pro\loyp\libs\BalanceInvoicesOutCleanup($this->app);
		$bce->setDocNumber($docNumber);
		$bce->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'recalc-points': return $this->recalcPoints();
			case 'reaccounting-points': return $this->reaccountingPoints();
			case 'balance-cleanup': return $this->balanceCleanup();
		}

		parent::onCliAction($actionId);
	}
}
