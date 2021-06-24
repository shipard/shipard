<?php

namespace e10pro\canteen;
use \e10\Utility, \e10\utils;


/**
 * Class WeekMenuCloseCookingFoods
 * @package e10pro\canteen
 */
class WeekMenuCloseCookingFoods extends Utility
{
	var $canteenNdx = 0;
	/** @var \e10pro\canteen\WeekMenuEngine */
	var $canteenEngine = NULL;

	var $thisWeekBegin;
	var $nextWeekBegin;

	function setCanteen($canteenNdx)
	{
		$this->canteenNdx = $canteenNdx;

	}

	function init()
	{
		$today = utils::today();
		$this->thisWeekBegin = clone $today->modify(('Monday' === $today->format('l')) ? 'monday this week' : 'last monday');
		$this->nextWeekBegin = clone $this->thisWeekBegin;
		$this->nextWeekBegin->add (new \DateInterval('P7D'));
	}

	function loadData()
	{
		$weekYear = intval($this->nextWeekBegin->format('o'));
		$weekNumber = intval($this->nextWeekBegin->format('W'));
		$this->canteenEngine = new \e10pro\canteen\WeekMenuEngine($this->app);
		$this->canteenEngine->setWeek($this->canteenNdx, $weekYear, $weekNumber);
		$this->canteenEngine->run();
	}

	function closeSelectCookingFoods($force = 0)
	{
		$this->init();
		$this->loadData();

		$wantedOrderState = 0;
		$newOrderState = 1;
		if ($force)
		{
			$wantedOrderState = 1;
			$newOrderState = 2;
		}

		foreach ($this->canteenEngine->menus as $menuNdx => $menu)
		{
			$personsToNotify = [];
			$ordersToNotify = [];

			foreach ($menu['foods'] as $dayId => $dayFoods)
			{
				$dayFoodStat = isset($this->canteenEngine->datesStats[$menuNdx][$dayId]) ? $this->canteenEngine->datesStats[$menuNdx][$dayId] : NULL;
				foreach ($dayFoods as $foodIndex => $food)
				{
					$foodOrder = ($dayFoodStat !== NULL) ? $dayFoodStat['foods'][$food['ndx']]['order'] : 100;
					$foodWin = $foodOrder < $this->canteenEngine->canteenCfg['lunchCookFoodCount'];
					if ($foodWin)
						continue;

					$newFood = \e10\searchArray($dayFoodStat['foods'], 'order', 1);
					if (!$newFood)
						$newFood = \e10\searchArray($dayFoodStat['foods'], 'order', 0);

					$this->db()->query ('UPDATE [e10pro_canteen_menuFoods] SET [notCooking] = %i', 1, ' WHERE [ndx] = %i', $food['ndx']);

					$updateOrder = ['orderState' => $newOrderState];
					if ($force && $newFood)
						$updateOrder['food'] = $newFood['foodNdx'];

					if ($force && 1)
					{  // TODO: settings?
						$updateOrder['food'] = 0;
						unset($updateOrder['orderState']);
					}

					$rowsOrders = $this->db()->query ('SELECT * FROM [e10pro_canteen_foodOrders] WHERE [menu] = %i', $menuNdx,
						' AND [food] = %i', $food['ndx'], ' AND [orderState] = %i', $wantedOrderState);

					foreach ($rowsOrders as $r)
					{
						$this->db()->query ('UPDATE [e10pro_canteen_foodOrders] SET ', $updateOrder, ' WHERE [ndx] = %i', $r['ndx']);
						if (!in_array($r['personOrder'], $personsToNotify))
							$personsToNotify[] = $r['personOrder'];

						$ordersToNotify[] = $r['ndx'];
					}
				}
			}

			// -- set menu status
			$this->db()->query ('UPDATE [e10pro_canteen_menus] SET [orderState] = %i', $newOrderState, ' WHERE [ndx] = %i', $menuNdx);

			$this->notifyPersons($menu, $personsToNotify, $force);
		}
	}

	function notifyPersons($menu, $persons, $force)
	{
		foreach ($persons as $personNdx)
		{
			$webServer = $this->app()->cfgItem ('e10.web.servers.list.'.$this->canteenEngine->canteenCfg['webServer'], NULL);
			if (!$webServer)
				continue;

			$urlKey = $this->db()->query('SELECT * FROM [e10_web_wuKeys] WHERE [person] = %i', $personNdx,
				' AND [keyType] = %i', 1, ' AND [webServer] = %i', $this->canteenEngine->canteenCfg['webServer'])->fetch();
			if (!$urlKey)
				continue;

			$emails = $this->personsEmails($personNdx);
			if (!count($emails))
				continue;

			$linkUrl = 'https://'.$webServer['urlStart'].'/user/k/'.$urlKey['keyValue'];

			if ($force)
				$subject = 'ZRUŠENÁ objednávka jídla - '.$menu['fullName'];
			else
				$subject = 'Výběr jídla - '.$menu['fullName'];
			$body = "Dobrý den, \n\n";
			if ($force)
			{
				$body .= "museli jsme bohužel zrušit objednávku jídla, které se nebude vařit.\n\n";
				$body .= "Jiné jídlo můžete vybrat kliknutím na odkaz:\n$linkUrl\n\n";
			}
			else
			{
				$body .= "některá z jídel, která jste vybrali, bohužel nebudou k dispozici.\n\n";
				$body .= "Změnu objednaného jídla uskutečníte kliknutím na odkaz:\n$linkUrl\n\n";
			}

			$msg = new \Shipard\Report\MailMessage($this->app);
			$msg->setFrom ($this->canteenEngine->canteenCfg['fn'], $this->app->cfgItem ('options.core.ownerEmail'));
			$msg->setTo($emails[0]);

			$msg->setSubject($subject);
			$msg->setBody($body, FALSE);
			$msg->sendMail();
		}
	}

	function personsEmails($personNdx)
	{
		$q[] = 'SELECT recid, valueString FROM [e10_base_properties]';
		array_push ($q,' WHERE 1');
		array_push ($q,' AND [tableid] = %s', 'e10.persons.persons', ' AND [recid] = %i', $personNdx);
		array_push ($q,' AND [group] = %s', 'contacts', ' AND property = %s', 'email');

		$emails = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$emails[] = $r['valueString'];
		}

		return $emails;
	}
}