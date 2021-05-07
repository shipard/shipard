<?php

namespace E10\Base;

use \Shipard\Base\DeviceInfo, \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableDocsLog
 * @package E10\Base
 */
class TableAuthLog extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.authLog', 'e10_base_authLog', 'Log přihlašování');
	}

	public function tableIcon ($recData, $options = NULL)
	{
		$eventType = $this->app()->cfgItem ('e10.base.authLog.events.'.$recData['eventType'], FALSE);

		if ($eventType)
			return $eventType['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$eventType = $this->app()->cfgItem ('e10.base.authLog.events.'.$recData['eventType']);

		$hdr ['info'][] = ['class' => 'h2', 'value' => $eventType['name']];


		$itemTop = [
				['icon' => 'icon-clock-o', 'text' => utils::datef ($recData['created'], '%x'), 'class' => ''],
				['icon' => 'icon-sitemap', 'text' => $recData['ipaddress'], 'class' => ''],
		];

		$hdr ['info'][] = ['class' => 'info', 'value' => $itemTop];

		if ($recData['eventType'] == 0)
		{
			$hdr ['info'][] = array ('class' => 'title', 'value' => $recData ['eventTitle']);
		}

		return $hdr;
	}
}


/**
 * Class ViewAuthLog
 * @package E10\Base
 */
class ViewAuthLog extends TableView
{
	var $eventTypes;
	var $thisIsHosting = FALSE;

	public function init ()
	{
		parent::init();

		$this->eventTypes = $this->app()->cfgItem ('e10.base.authLog.events');
		if ($this->app()->model()->table ('e10pro.hosting.server.datasources') !== FALSE)
			$this->thisIsHosting = TRUE;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT log.*, persons.fullName as personName, ';
		array_push($q, 'devices.name AS deviceName, devices.clientInfo AS clientInfo, devices.clientTypeId AS clientTypeId, devices.clientVersion AS clientVersion');

		if ($this->thisIsHosting)
			array_push($q, ', datasources.name as dsName, datasources.shortName as dsShortName');

		array_push($q, ' FROM [e10_base_authLog] AS log');
		array_push ($q, 'LEFT JOIN e10_persons_persons as persons ON log.user = persons.ndx');
		array_push ($q, 'LEFT JOIN e10_base_devices as [devices] ON log.deviceId = devices.id');

		if ($this->thisIsHosting)
			array_push ($q, 'LEFT JOIN e10pro_hosting_server_datasources AS datasources ON log.dsGid = datasources.gid');

		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, "AND (");
			array_push ($q, "persons.[fullName] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR log.[login] LIKE %s", '%'.$fts.'%');
			array_push ($q, ") ");
		}

		array_push ($q, ' ORDER BY [ndx] DESC' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$e = $this->eventTypes[$item['eventType']];

		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon($item);

		if ($item['personName'] && $item['login'] !== '')
			$listItem ['t1'] = $item['login'].' - '.$item['personName'];
		elseif ($item['personName'] && $item['login'] === '')
			$listItem ['t1'] = $item['personName'];
		else
			$listItem ['t1'] = $item['login'];

		$listItem ['i1'] = '#'.$item['ndx'];

		$props3 = [];
		$props2 [] = ['icon' => 'icon-clock-o', 'text' => utils::datef ($item['created'], '%D, %T'), 'class' => ''];
		$props2 [] = ['icon' => 'icon-sitemap', 'text' => $item['ipaddress'], 'class' => ''];

		if ($item['session'] !== '')
		{
			$sid = substr($item['session'], 0, 3).'…'.substr($item['session'], -5, 5);
			if ($this->app()->sessionId === $item['session'])
				$props2 [] = ['icon' => 'icon-ticket', 'text' => $sid, 'class' => 'e10-bold'];
			else
				$props2 [] = ['icon' => 'icon-ticket', 'text' => $sid, 'class' => ''];
		}

		if ($this->thisIsHosting)
		{
			if ($item['dsShortName'])
				$props2 [] = ['icon' => 'icon-database', 'text' => $item['dsShortName'], 'class' => ''];
			elseif ($item['dsName'])
				$props2 [] = ['icon' => 'icon-database', 'text' => $item['dsName'], 'class' => ''];
		}

		if ($item['deviceId'] !== '')
		{
			$sid = substr($item['deviceId'], 0, 3).'…'.substr($item['deviceId'], -5, 5);
			$di = ['icon' => 'icon-laptop', 'text' => $sid, 'class' => ''];

			$props3 [] = $di;
		}

		if ($item['clientInfo'])
		{
			$d = new DeviceInfo();
			$d->checkDeviceInfo($item, '');

			if (isset($d->deviceInfo['appLine']))
				$props3 [] = $d->deviceInfo['appLine'];
			if (isset($d->deviceInfo['browserLine']))
				$props3 [] = $d->deviceInfo['browserLine'];
			if (isset($d->deviceInfo['osLine']))
				$props3 [] = $d->deviceInfo['osLine'];
		}

		$listItem ['t2'] = $props2;
		if (count($props3))
			$listItem ['t3'] = $props3;

		if ($e['error'])
			$listItem ['class'] = 'e10-warning2';

		return $listItem;
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class ViewDetailAuthLog
 * @package E10\Base
 */
class ViewDetailAuthLog extends TableViewDetail
{
	public function createToolbar ()
	{
		return [];
	}

	public function createDetailContent ()
	{
		$i = $this->item;
		$info = [];

		if ($this->app()->model()->table ('e10pro.hosting.server.datasources') !== FALSE && $i['dsGid'])
		{
			$dsRecData = $this->app()->db()->query('SELECT * FROM [e10pro_hosting_server_datasources] WHERE gidStr = %s', $i['dsGid'])->fetch();
			if ($dsRecData)
			{
				$info[] = [
					'p1' => 'Zdroj dat',
					't1' => ['text' => $dsRecData['name'], 'suffix' => '#'.$dsRecData['gid']]
				];
			}
			else
			{
				$info[] = ['p1' => 'Zdroj dat', 't1' => ['text' => '!!! #'.$dsRecData['dsGid'].' !!!', 'class' => 'e10-error']];
			}
		}

		if ($i['login'] !== '')
			$info[] = ['p1' => 'Přihlašovací email', 't1' => $i['login']];

		if ($i['user'])
		{
			$tablePersons = $this->app()->table('e10.persons.persons');
			$userRecData = $tablePersons->loadItem ($i['user']);
			$info[] = [
				'p1' => 'Uživatel',
				't1' => [
					['text' => $userRecData['fullName'], 'suffix' => $userRecData['login'], 'class' => ''],
					['text' => '#'.$userRecData['id'], 'class' => 'id pull-right']
				]
			];
		}

		$info[] = ['p1' => 'IP adresa', 't1' => $i['ipaddress']];

		if ($i['deviceId'] !== '')
		{
			$props = [];

			$deviceRecData = $this->app()->db()->query('SELECT * FROM [e10_base_devices] WHERE id = %s', $i['deviceId'])->fetch();

			$d = new DeviceInfo();
			$d->checkDeviceInfo($deviceRecData);

			$deviceId = ['text' => $i['deviceId'], 'class' => 'block', 'icon' => 'icon-laptop'];
			if ($deviceRecData['name'] !== '')
				$deviceId['suffix'] = $deviceRecData['name'];
			$props [] = $deviceId;

			if (isset($d->deviceInfo['appLine']))
				$props [] = $d->deviceInfo['appLine'];
			if (isset($d->deviceInfo['browserLine']))
				$props [] = $d->deviceInfo['browserLine'];
			if (isset($d->deviceInfo['osLine']))
				$props [] = $d->deviceInfo['osLine'];

			if ($deviceRecData['clientInfo'] !== '')
				$props [] = ['text' => $deviceRecData['clientInfo'], 'class' => 'block e10-off e10-small'];

			$info[] = ['p1' => 'Zařízení', 't1' => $props];
		}

		$info[] = ['p1' => 'ssid', 't1' => $i['session']];

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

		$this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);


		$failFileName = __APP_DIR__.'/tmp/__FAIL_LOGIN_'.$i['ndx'].'.json';
		if (is_file($failFileName))
		{
			$failInfo = json_decode(file_get_contents($failFileName), TRUE);

			if ($failInfo)
			{
				$info = [];

				foreach ($failInfo as $failKey => $failItem)
				{
					if (is_array($failItem))
					{
						$info[] = ['p1' => $failKey];
						foreach ($failItem as $failKey2 => $failItem2)
						{
							$info[] = ['p1' => $failKey2, 't1' => $failItem2];
						}
					}
					else
					{
						$info[] = ['p1' => $failKey, 't1' => $failItem];
					}
				}

				$this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
					'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
			}
		}
	}
}

