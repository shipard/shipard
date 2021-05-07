<?php

namespace integrations\hooks\in\services\wooCommerce;
use e10\utils;


/**
 * Class WooCommerce
 * @package integrations\hooks\in\services\wooCommerce
 */
class WooCommerce extends \integrations\hooks\in\services\HookCore
{
	function doPerson()
	{
		$e = new \integrations\hooks\in\services\wooCommerce\WooCommercePerson($this->app());
		$e->setHook($this);
		$e->run();
	}

	function doOrder()
	{
		$e = new \integrations\hooks\in\services\wooCommerce\WooCommerceOrder($this->app());
		$e->setHook($this);
		$e->run();
	}

	public function run()
	{
		if (!isset($this->inParams['headers']['x-wc-webhook-resource']))
		{
			return;
		}

		if ($this->inParams['headers']['x-wc-webhook-resource'] === 'customer')
			$this->doPerson();
		elseif ($this->inParams['headers']['x-wc-webhook-resource'] === 'order')
			$this->doOrder();
	}
}