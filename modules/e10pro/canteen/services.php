<?php

namespace e10pro\canteen;
use e10\utils;

/**
 * Class ModuleServices
 * @package e10pro\canteen
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function createMenuRecipients()
	{
		$weekOffsetArg = $this->app->arg('week-offset');
		if ($weekOffsetArg !== FALSE)
			$weekOffsetArg = intval($weekOffsetArg);

		$e = new \e10pro\canteen\WeekMenuSender($this->app);
		$e->createMenuRecipients($weekOffsetArg);
	}

	function sendUnsentMenus()
	{
		$e = new \e10pro\canteen\WeekMenuSender($this->app);
		$e->sendUnsent();
	}

	function sendMenuToSupplier($fromCron = 0)
	{
		$e = new \e10pro\canteen\SupplierMenuSender($this->app);
		$e->fromCron = $fromCron;
		$e->run();

		return TRUE;
	}

	public function closeSelectCookingFoods ()
	{
		$canteens = $this->app->cfgItem ('e10pro.canteen.canteens', []);
		foreach ($canteens as $canteenNdx => $canteen)
		{
			if ($canteen['lunchMenuCookingType'] == 0)
				continue;

			$now = new \DateTime();
			$today = utils::today();
			$thisWeekBegin = clone $today->modify(('Monday' === $today->format('l')) ? 'monday this week' : 'last monday');

			$startDate = new \DateTime($thisWeekBegin->format('Y-m-d').' '.$canteen['closeSelectCookingFoodsTime'].':00');
			if ($canteen['closeSelectCookingFoodsDay'])
				$startDate->add (new \DateInterval('P'.$canteen['closeSelectCookingFoodsDay'].'D'));

			if ($now < $startDate)
				continue;
			if ($now->format ('Y-m-d') !== $startDate->format ('Y-m-d'))
				continue;

			$doneFileName = __APP_DIR__.'/tmp/closeSelectCookingFoods-'.$canteenNdx.'-'.$startDate->format ('Y-m-d').'.done';
			if (is_readable($doneFileName))
				continue;

			$e = new \e10pro\canteen\WeekMenuCloseCookingFoods($this->app);
			$e->setCanteen($canteenNdx);
			$e->closeSelectCookingFoods(0);

			touch ($doneFileName);
		}
	}

	public function forceSelectCookingFoods ()
	{
		$canteens = $this->app->cfgItem ('e10pro.canteen.canteens', []);
		foreach ($canteens as $canteenNdx => $canteen)
		{
			if ($canteen['lunchMenuCookingType'] == 0)
				continue;

			$now = new \DateTime();
			$today = utils::today();
			$thisWeekBegin = clone $today->modify(('Monday' === $today->format('l')) ? 'monday this week' : 'last monday');

			$startDate = new \DateTime($thisWeekBegin->format('Y-m-d').' '.$canteen['forceSelectCookingFoodsTime'].':00');
			if ($canteen['forceSelectCookingFoodsDay'])
				$startDate->add (new \DateInterval('P'.$canteen['forceSelectCookingFoodsDay'].'D'));

			if ($now < $startDate)
				continue;
			if ($now->format ('Y-m-d') !== $startDate->format ('Y-m-d'))
				continue;

			$doneFileName = __APP_DIR__.'/tmp/forceSelectCookingFoods-'.$canteenNdx.'-'.$startDate->format ('Y-m-d').'.done';
			if (is_readable($doneFileName))
				continue;

			$e = new \e10pro\canteen\WeekMenuCloseCookingFoods($this->app);
			$e->setCanteen($canteenNdx);
			$e->closeSelectCookingFoods(1);

			touch ($doneFileName);
		}
	}

	public function changeOrderToFee ()
	{
		$me = new \e10pro\canteen\MonthEngine($this->app);

		$dateBeginStr = $this->app->arg('date-begin');
		$dateEndStr = $this->app->arg('date-end');
		$canteenNdx = intval($this->app->arg('canteen'));

		if (!$dateBeginStr || !$dateEndStr || !$canteenNdx)
			return;

		$me->setPeriod(utils::createDateTime($dateBeginStr), utils::createDateTime($dateEndStr));
		$me->canteenNdx = $canteenNdx;
		$me->changeOrderToFee();
	}

	public function generateInvoices ()
	{
		$year = intval($this->app->arg('year'));
		if (!$year)
		{
			echo "ERROR: param `--year=` not found\n";
			return;
		}
		$month = intval($this->app->arg('month'));
		if (!$month)
		{
			echo "ERROR: param `--month=` not found\n";
			return;
		}

		ini_set('memory_limit', '1024M');

		$ig = new \e10pro\canteen\libs\InvoicesGenerator($this->app);
		$ig->year = $year;
		$ig->month = $month;

		$ig->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'close-select-cooking-foods': return $this->closeSelectCookingFoods();
			case 'force-select-cooking-foods': return $this->forceSelectCookingFoods();
			case 'create-menu-recipients': return $this->createMenuRecipients();
			case 'send-unsent-menus': return $this->sendUnsentMenus();
			case 'send-menu-to-supplier': return $this->sendMenuToSupplier();
			case 'change-order-fee': return $this->changeOrderToFee();
			case 'generate-invoices': return $this->generateInvoices();
		}

		return parent::onCliAction($actionId);
	}

	public function onCronHourly ()
	{
		$this->createMenuRecipients();
		$this->sendUnsentMenus();
	}

	public function onCronEver ()
	{
		$this->closeSelectCookingFoods();
		$this->forceSelectCookingFoods();

		$this->sendMenuToSupplier(1);
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case	'ever':   $this->onCronEver (); break;
			case  'hourly': $this->onCronHourly(); break;
		}
		return TRUE;
	}
}
