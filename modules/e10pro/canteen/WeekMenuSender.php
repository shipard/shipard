<?php

namespace e10pro\canteen;
use \e10\Utility, \e10\utils;


/**
 * Class WeekMenuSender
 * @package e10pro\canteen
 */
class WeekMenuSender extends Utility
{
	/** @var \e10\web\TableWuKeys */
	var $tableWuKeys;
	/** @var \e10pro\canteen\TableMenus */
	var $tableMenus;
	/** @var \e10pro\canteen\TableCanteens */
	var $tableCanteens;
	/** @var \e10pro\canteen\TableFoodOrders */
	var $tableFoodOrders;

	public function init()
	{
		$this->tableWuKeys = $this->app()->table('e10.web.wuKeys');
		$this->tableMenus = $this->app()->table('e10pro.canteen.menus');
		$this->tableCanteens = $this->app()->table('e10pro.canteen.canteens');
		$this->tableFoodOrders = $this->app()->table('e10pro.canteen.foodOrders');
	}

	public function createMenuRecipients($weekOffset = FALSE)
	{
		$this->init();

		$this->db()->query ('DELETE FROM e10pro_canteen_menuRecipientsPersons WHERE menu = 0');

		$allCanteens = $this->app()->cfgItem ('e10pro.canteen.canteens', []);
		foreach ($allCanteens as $canteenNdx => $canteen)
		{
			if ($weekOffset === FALSE)
			{
				$this->checkWeek($canteen, 0);
				$this->checkWeek($canteen, 1);
			}
			else
			{
				$this->checkWeek($canteen, $weekOffset, TRUE);
			}
		}
	}

	function checkWeek ($canteen, $move, $ignoreToday = FALSE)
	{
		$firstDay = utils::today();
		$activeDate = clone $firstDay->modify(('Monday' === $firstDay->format('l')) ? 'monday this week' : 'last monday');

		$weekDate = clone $activeDate;
		if ($move > 0)
			$weekDate->add (new \DateInterval('P'.(abs($move)*7).'D'));
		elseif ($move < 0)
			$weekDate->sub (new \DateInterval('P'.(abs($move)*7).'D'));

		$weekId = $weekDate->format('o-W');
		$weekYear = intval($weekDate->format('o'));
		$weekNumber = intval($weekDate->format('W'));

		$this->createWeekRecipients($weekId, $canteen, $ignoreToday);
	}

	function createWeekRecipients($dateId, $canteen, $ignoreToday = FALSE)
	{
		$activeMenus = $this->activeMenus($dateId, $canteen);
		if (!count($activeMenus))
			return;

		//$this->db()->query('DELETE FROM [e10pro_canteen_menuRecipientsPersons] WHERE [canteen] = %i', $canteen['ndx']);
		//$this->db()->query('DELETE FROM [e10_web_wuKeys] WHERE [webServer] = %i', $canteen['webServer']);

		$today = utils::today();

		$q[] = 'SELECT * FROM [e10pro_canteen_menuRecipientsDefs]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [canteen] = %i', $canteen['ndx']);
		array_push ($q, ' AND [docState] = 4000');
		array_push ($q, ' ORDER BY ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['recipientType'] === 0)
			{ // person
				if (!$r['person'])
					continue;

				if (isset($canteen['autoOrderFoods']) && $canteen['autoOrderFoods'])
					$this->addPersonAutoOrder ($canteen, $dateId, $r['person'], $activeMenus, $ignoreToday);

				$emails = $this->personsEmails($r['person']);
				if (!count($emails))
					continue;

				foreach ($emails as $email)
					$this->addPersonRecipient ($canteen, $dateId, $r['person'], $email, $activeMenus);

				if ($canteen['webServer'])
					$this->tableWuKeys->checkUrlKey($canteen['webServer'], $r['person']);
			}
			else if ($r['recipientType'] === 1)
			{ // by relation
				$qr = [];
				$qr[] = 'SELECT * FROM [e10_persons_relations]';
				array_push($qr, ' WHERE 1');
				array_push($qr, ' AND [category] = %i', $r['categoryType']);
				array_push($qr, ' AND [parentPerson] = %i', $r['categoryPerson']);

				array_push($qr, ' AND ([validFrom] IS NULL OR [validFrom] <= %d)', $today,
					' AND ([validTo] IS NULL OR [validTo] >= %d)', $today);

				array_push($qr, ' AND [docState] = %i', 4000);

				$rowsRelations = $this->db()->query ($qr);

				foreach ($rowsRelations as $rr)
				{
					if (!$rr['person'])
						continue;

					if (isset($canteen['autoOrderFoods']) && $canteen['autoOrderFoods'])
						$this->addPersonAutoOrder ($canteen, $dateId, $rr['person'], $activeMenus, $ignoreToday);

					$emails = $this->personsEmails($rr['person']);
					if (!count($emails))
						continue;

					foreach ($emails as $email)
						$this->addPersonRecipient ($canteen, $dateId, $rr['person'], $email, $activeMenus);

					if ($canteen['webServer'])
						$this->tableWuKeys->checkUrlKey($canteen['webServer'], $rr['person']);
				}
			}
			else if ($r['recipientType'] === 2)
			{ // by label
				$qr = [];
				$qr[] = 'SELECT * FROM [e10_persons_persons] AS persons';
				array_push($qr, ' WHERE 1');
				array_push($qr, ' AND [persons].[docState] = %i', 4000);
				array_push($qr, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE persons.ndx = recid AND tableId = %s', 'e10.persons.persons');
				array_push($qr, ' AND ([group] = %s', 'personsTags', ' AND [clsfItem] = %i', $r['personLabel'], ')');
				array_push($qr, ')');

				$rowsPersons = $this->db()->query ($qr);

				foreach ($rowsPersons as $rr)
				{
					if (isset($canteen['autoOrderFoods']) && $canteen['autoOrderFoods'])
						$this->addPersonAutoOrder ($canteen, $dateId, $rr['ndx'], $activeMenus, $ignoreToday);

					$emails = $this->personsEmails($rr['ndx']);
					if (!count($emails))
						continue;

					foreach ($emails as $email)
						$this->addPersonRecipient ($canteen, $dateId, $rr['ndx'], $email, $activeMenus);

					if ($canteen['webServer'])
						$this->tableWuKeys->checkUrlKey($canteen['webServer'], $rr['ndx']);
				}
			}
		}
	}

	function addPersonRecipient ($canteen, $dateId, $personNdx, $email, $activeMenus)
	{
		foreach ($activeMenus as $activeMenuNdx)
		{
			$q = [];
			$q[] = 'SELECT * FROM [e10pro_canteen_menuRecipientsPersons]';
			array_push($q, ' WHERE 1');
			array_push($q, ' AND [canteen] = %i', $canteen['ndx']);
			array_push($q, ' AND [dateId] = %s', $dateId);
			array_push($q, ' AND [person] = %i', $personNdx);
			array_push($q, ' AND [menu] = %i', $activeMenuNdx);
			array_push($q, ' AND [email] = %s', $email);

			$exist = $this->db()->query($q)->fetch();
			if ($exist)
				return;

			$disableSend = 0;
			if (isset($canteen['sendingEmailsDisabled']) && $canteen['sendingEmailsDisabled'])
				$disableSend = 1;

			$newItem = ['canteen' => $canteen['ndx'], 'menu' => $activeMenuNdx, 'dateId' => $dateId, 'person' => $personNdx, 'email' => $email, 'disableSend' => $disableSend];
			$this->db()->query('INSERT INTO [e10pro_canteen_menuRecipientsPersons]', $newItem);
		}
	}

	function addPersonAutoOrder ($canteen, $dateId, $personNdx, $activeMenus, $ignoreToday)
	{
		$today = utils::today();
		foreach ($activeMenus as $activeMenuNdx)
		{
			$menuRecData = $this->tableMenus->loadItem($activeMenuNdx);
			$date = new \DateTime($menuRecData['dateFrom']);

			for ($day = 0; $day < 5; $day++)
			{
				if ($date < $today && !$ignoreToday)
				{
					$date->add(new \DateInterval('P1D'));
					continue;
				}

				// -- search first food
				$firstFood = $this->db()->query('SELECT * FROM [e10pro_canteen_menuFoods] WHERE [canteen] = %i', $canteen['ndx'],
					' AND [menu] = %i', $activeMenuNdx, ' AND [date] = %d', $date, ' AND docState = %i', 4000, ' AND [foodIndex] = %i', 1)->fetch();
				if ($firstFood)
				{
					// -- search existed order
					$existedOrder = $this->db()->query('SELECT * FROM [e10pro_canteen_foodOrders] WHERE [canteen] = %i', $canteen['ndx'],
						' AND [menu] = %i', $activeMenuNdx, ' AND [date] = %d', $date, ' AND [personOrder] = %i', $personNdx)->fetch();

					if (!$existedOrder)
					{
						$newOrder = [
							'canteen' => $canteen['ndx'], 'menu' => $activeMenuNdx, 'date' => $date, 'personOrder' => $personNdx,
							'orderNumber' => 0, 'food' => $firstFood['ndx'], 'firstChoiceFood' => $firstFood['ndx'],
							'docState' => 4000,
							'docStateMain' => 2,
						];

						$addFoods = [];
						$enabledAddFoods = $this->tableCanteens->addFoodsList($canteen, $personNdx, $date);
						foreach ($enabledAddFoods as $eafNdx)
							$addFoods['addFood_'.$eafNdx] = 1;
						$newOrder['addFoods'] = json_encode($addFoods);

						$this->db()->query('INSERT INTO [e10pro_canteen_foodOrders] ', $newOrder);
						$newOrderNdx = intval ($this->db()->getInsertId ());
						$this->tableFoodOrders->docsLog($newOrderNdx);
					}
				}

				$date->add(new \DateInterval('P1D'));
			}
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

	function activeMenus($dateId, $canteen)
	{
		$menus = [];

		$dateWorkingFrom = NULL;
		if (isset($canteen['dateWorkingFrom']))
			$dateWorkingFrom = new \DateTime($canteen['dateWorkingFrom']);

		$qm[] = 'SELECT * FROM [e10pro_canteen_menus]';
		array_push($qm, ' WHERE 1');
		array_push($qm, ' AND [canteen] = %i', $canteen['ndx']);
		array_push($qm, ' AND [dateId] = %s', $dateId);
		array_push($qm, ' AND [docState] = %i', 4000);

		$rows = $this->db()->query($qm);
		foreach ($rows as $r)
		{
			if ($dateWorkingFrom && $dateWorkingFrom > $r['dateTo'])
				continue;

			$menus[] = $r['ndx'];
		}

		return $menus;
	}

	function sendUnsent ()
	{
		$q[] = 'SELECT recipients.*, persons.fullName AS personName, canteens.shortName AS canteenName, menus.fullName AS menuName';
		array_push ($q, ' FROM [e10pro_canteen_menuRecipientsPersons] AS recipients');
		array_push ($q, ' LEFT JOIN e10pro_canteen_canteens AS canteens ON recipients.canteen = canteens.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON recipients.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10pro_canteen_menus AS menus ON recipients.menu = menus.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [sent] = %i', 0, ' AND [disableSend] = %i', 0);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$canteen = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$r['canteen'], NULL);
			if (!$canteen)
				continue;
			$webServer = $this->app()->cfgItem ('e10.web.servers.list.'.$canteen['webServer'], NULL);
			if (!$webServer)
				continue;

			$urlKey = $this->db()->query('SELECT * FROM [e10_web_wuKeys] WHERE [person] = %i', $r['person'],
				' AND [keyType] = %i', 1, ' AND [webServer] = %i', $canteen['webServer'])->fetch();
			if (!$urlKey)
				continue;

			$linkUrl = 'https://'.$webServer['urlStart'].'/user/k/'.$urlKey['keyValue'];

			$subject = $r['menuName'].' - '.$r['personName'];
			$body = "Dobrý den, \n\n";
			$body .= "k dispozici je nový jídelní lístek.\n\n";
			$body .= "Objednávku jídla uskutečníte kliknutím na odkaz:\n$linkUrl\n\n";

			$msg = new \Shipard\Report\MailMessage($this->app);
			$msg->setFrom ($r['canteenName'], $this->app->cfgItem ('options.core.ownerEmail'));
			$msg->setTo($r['email']);

			$msg->setSubject($subject);
			$msg->setBody($body, FALSE);
			$msg->sendMail();

			$update = ['sentDate' => new \DateTime(), 'sent' => 1];
			$this->db()->query ('UPDATE [e10pro_canteen_menuRecipientsPersons] SET ', $update, ' WHERE ndx = %i', $r['ndx']);
		}
	}
}

