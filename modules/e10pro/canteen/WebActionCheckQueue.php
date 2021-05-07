<?php

namespace e10pro\canteen;
use \e10\web\WebAction, \e10\utils;


/**
 * Class WebActionCheckQueue
 * @package e10pro\canteen
 */
class WebActionCheckQueue extends WebAction
{
	var $canteenNdx = 0;
	var $menuNdx = 0;
	var $date = NULL;
	var $foodNdx = 0;
	var $personNdx = 0;
	var $orderNumber = 0;
	var $personOptionsFoodNdx = 0;

	protected function updateQueueOrders()
	{
		$limit = new \DateTime('-1 minute');
		$update = ['takeInProgress' => 0, 'takeDone' => 1, 'takeDateTime' => new \DateTime(), 'orderState' => 2];
		$this->db()->query ('UPDATE [e10pro_canteen_foodOrders] SET ', $update,
			' WHERE [takeInProgress] = %i', 1, ' AND [takeInProgressDateTime] < %t', $limit);
	}

	function doIt()
	{
		$this->updateQueueOrders();

		$this->result['run'] = [
			['cmd' => 'reloadElementId', 'id' => 'e10pro-canteen-ServingFoodQueueWidget'],
			['cmd' => 'reloadElementId', 'id' => 'e10pro-canteen-ServingFoodStatsWidget'],
		];

		$this->setSuccess();
	}

	public function run ()
	{
		$this->canteenNdx = intval($this->params['canteen']);

		$this->doIt();
	}
}