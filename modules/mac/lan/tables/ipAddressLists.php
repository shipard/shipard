<?php

namespace mac\lan;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableIPAddressLists
 * @package mac\lan
 */
class TableIPAddressLists extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.ipAddressLists', 'mac_lan_ipAddressLists', 'Seznamy IP adres');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $h;
	}
}


/**
 * Class ViewIPAddressLists
 * @package mac\lan
 */
class ViewIPAddressLists extends TableView
{
	var $address = [];

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
		$listItem ['t2'] = ' .';

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->address [$item ['pk']]))
			$item ['t2'] = $this->address [$item ['pk']];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [al].*';
		array_push ($q, ' FROM [mac_lan_ipAddressLists] AS [al]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([al].[fullName] LIKE %s)", '%'.$fts.'%');

		$this->queryMain ($q, '[al].', ['[al].[fullName]', '[al].[ndx]']);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$q[] = 'SELECT [rows].*, [addr].[fullName] AS addrFullName, [addr].[hostName] AS addrHostName ';
		array_push($q, ' FROM [mac_lan_ipAddressListsRows] AS [rows]');
		array_push($q, ' LEFT JOIN [mac_lan_ipAddress] AS [addr] ON [rows].[address] = [addr].ndx');
		array_push($q, ' WHERE [rows].addressList IN %in', $this->pks);
		array_push($q, ' ORDER BY [addr].[fullName], [rows].rowOrder, [rows].ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->address[$r['addressList']][] = [
				'text' => $r['addrFullName'], 'class' => 'label label-default', 'icon' => 'icon-crosshairs',
				'suffix' => $r['addrHostName']
			];
		}
	}
}


/**
 * Class FormIPAddressList
 * @package mac\lan
 */
class FormIPAddressList extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'Adresy', 'icon' => 'icon-crosshairs'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addList ('rows');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

/**
 * Class ViewDetailIPAddressList
 * @package mac\lan
 */
class ViewDetailIPAddressList extends TableViewDetail
{
}

