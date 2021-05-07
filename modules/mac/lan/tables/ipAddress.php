<?php

namespace mac\lan;

require_once __APP_DIR__ . '/e10-modules/e10/web/web.php';

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableIPAddress
 * @package mac\lan
 */
class TableIPAddress extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.ipAddress', 'mac_lan_ipAddress', 'IP adresy');
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['ipAddress']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		$recData['ipAddress'] = gethostbyname($recData['hostName']);
	}
}


/**
 * Class ViewIPAddress
 * @package mac\lan
 */
class ViewIPAddress extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['hostName'];
		$listItem ['i2'] = $item['ipAddress'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [addr].*';
		array_push ($q, ' FROM [mac_lan_ipAddress] AS [addr]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND (addr.[fullName] LIKE %s', '%'.$fts.'%',
				'OR [addr].[ipAddress] LIKE %s', '%'.$fts.'%',
				'OR [addr].[hostName] LIKE %s', '%'.$fts.'%',
				')');

		$this->queryMain ($q, '[addr].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormIPAddress
 * @package mac\lan
 */
class FormIPAddress extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();

		$tabs ['tabs'][] = ['text' => 'IP adresa', 'icon' => 'icon-crosshairs'];

		$this->openTabs ($tabs);
			$this->openTab ();
				$this->addColumnInput ('fullName');
				$this->addColumnInput ('hostName');
				$this->addColumnInput ('ipAddress', self::coReadOnly);
			$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailIPAddress
 * @package mac\lan
 */
class ViewDetailIPAddress extends TableViewDetail
{
}

