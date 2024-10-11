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

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'recalc-points': return $this->recalcPoints();
		}

		parent::onCliAction($actionId);
	}
}
