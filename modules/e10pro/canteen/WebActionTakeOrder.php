<?php

namespace e10pro\canteen;
use \e10\web\WebAction, \e10\utils;


/**
 * Class WebActionTakeOrder
 * @package e10pro\canteen
 */
class WebActionTakeOrder extends WebAction
{
	var $orderNdx = 0;
	var $orderState = 0;

	function doIt()
	{
		if (!$this->orderNdx)
			return;

		$update = [];
		if ($this->orderState === 1)
		{
			$update['takeDone']  = 1;
			$update['takeDateTime'] = new \DateTime();
			$update['takeInProgress'] = 0;
		}
		elseif ($this->orderState === 2)
		{
			$update['takeDone']  = 0;
			$update['takeDateTime'] = NULL;
			$update['takeInProgress'] = 0;
		}

		if (!count($update))
			return;

		$this->db()->query('UPDATE [e10pro_canteen_foodOrders] SET ', $update, ' WHERE [ndx] = %i', $this->orderNdx);
		$this->result['run'] = [['cmd' => 'reloadParentPane'], ['cmd' => 'reloadElementId', 'id' => 'e10pro-canteen-ServingFoodStatsWidget']];
		$this->setSuccess();
	}

	public function run ()
	{
		$this->orderNdx = intval($this->params['order-ndx']);
		$this->orderState = intval($this->params['order-state']);

		$this->doIt();
	}
}
