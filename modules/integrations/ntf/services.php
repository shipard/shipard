<?php

namespace integrations\ntf;

use E10\utils;


/**
 * Class ModuleServices
 * @package integrations\ntf
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function extNtfDelivery()
	{
		$e = new \integrations\ntf\libs\DeliveryEngine($this->app);
		$e->run();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'ext-ntf-delivery': return $this->extNtfDelivery();
		}

		return parent::onCliAction($actionId);
	}
}
