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

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'recalc-points': return $this->recalcPoints();
			case 'reaccounting-points': return $this->reaccountingPoints();
		}

		parent::onCliAction($actionId);
	}
}
