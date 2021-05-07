<?php

namespace E10\Base;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;

class TableIPAddr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.ipaddr", "e10_base_ipaddr", "IP adresy");
	}
}


/**
 * Class ViewIPAddresses
 * @package E10\Base
 */

class ViewIPAddresses extends TableView
{
	public function init ()
	{
		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['ipaddress'];
		$listItem ['i1'] = $item['title'];

		$props = [];
		if ($item['lat'] != 0 || $item['lon'] != 0)
			$props[] = ['icon' => 'icon-map-marker', 'text' => $item['lat'].', '.$item['lon'], 'url' => 'https://maps.google.com/?q='.$item['lat'].','.$item['lon']];

		if ($item['personName'])
			$props[] = ['icon' => ($item['personCompany']) ? 'icon-building' : 'icon-user', 'text' => $item['personName']];

		if (count($props))
			$listItem ['t2'] = $props;
		else
			$listItem ['t2'] = '-';

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT ipaddr.*, persons.fullName as personName, persons.company as personCompany from [e10_base_ipaddr] AS ipaddr';
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON ipaddr.person = persons.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([title] LIKE %s OR [ipaddr] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND ipaddr.[docStateMain] < 4");

		// -- trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND ipaddr.[docStateMain] = 4");

		array_push ($q, ' ORDER BY [title], ipaddr.[ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
} // class ViewIPAddresses


/**
 * Class ViewDetailIPAddress
 * @package E10\Base
 */

class ViewDetailIPAddress extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class FormIPAddress
 * @package E10\Base
 */

class FormIPAddress extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ("ipaddress");
			$this->addColumnInput ("title");
			$this->addColumnInput ("lat");
			$this->addColumnInput ("lon");
			$this->addColumnInput ("person");
		$this->closeForm ();
	}
}

