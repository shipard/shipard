<?php

namespace e10\base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableDevices
 * @package e10\base
 */
class TableDevices extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.devices', 'e10_base_devices', 'Zařízení');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['clientTypeId'] !== '')
		{
			$p = explode ('.', $recData['clientTypeId']);
			if ((isset($p[1]) && $p[1] === 'cordova') || ($p[0] === 'mobile' && $p[1] === 'browser'))
				return 'icon-mobile';
		}
		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewDevices
 * @package e10\base
 */
class ViewDevices extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = ['text' => $item['id'], 'icon' => 'icon-laptop'];

		$listItem ['pk'] = $item ['ndx'];

		if ($item['ipTitle'])
			$listItem ['i1'] = $item['ipTitle'];
		else
			$listItem ['i1'] = $item['ipaddress'];

		$listItem ['i2'] = [
				['icon' => 'icon-user', 'text' => $item['userName']],
				['icon' => 'icon-sign-in', 'text' => utils::datef ($item['lastSeenOnline'], '%D, %T')]
		];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$d = new \Shipard\Base\DeviceInfo();
		$d->checkDeviceInfo($item);

		$props3 = [];
		if (isset($d->deviceInfo['appLine']))
			$props3 [] = $d->deviceInfo['appLine'];
		if (isset($d->deviceInfo['browserLine']))
			$props3 [] = $d->deviceInfo['browserLine'];
		if (isset($d->deviceInfo['osLine']))
			$props3 [] = $d->deviceInfo['osLine'];

		if (count($props3))
			$listItem ['t3'] = $props3;
		elseif ($item['clientInfo'] !== '')
			$listItem ['t3'] = ['icon' => 'icon-cog', 'text' => $item['clientInfo'], 'class' => 'e10-small'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT devices.*, users.fullName as userName, ipaddr.title as ipTitle FROM [e10_base_devices] AS devices';
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS users ON devices.currentUser = users.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_ipaddr] AS ipaddr ON devices.ipaddressndx = ipaddr.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' users.[fullName] LIKE %s', '%'.$fts.'%',
					' OR devices.[name] LIKE %s', '%'.$fts.'%',
					' OR devices.[id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'devices.', ['devices.[lastSeenOnline] DESC', 'devices.[name]', 'devices.[id]', 'devices.[ndx]']);
		$this->runQuery ($q);
	}
}

/**
 * Class FormDevice
 * @package e10\base
 */
class FormDevice extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
		$this->addColumnInput ('name');
		$this->addColumnInput ('id');
		$this->closeForm ();
	}
}

