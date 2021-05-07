<?php

namespace E10pro\Hosting\Server {

use \E10\Application, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\HeaderData, \E10\DbTable;

class TableRequests extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.hosting.server.requests", "e10pro_hosting_server_requests", "Požadavky na nové zdroje dat");
	}
}


/* 
 * ViewRequests
 * 
 */

class ViewRequests extends TableView
{
	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = "e10pro-hosting-server-requests";
		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = $item['email'];
		return $listItem;
	}
} // class ViewRequests


/**
 * Základní detail Požadavku
 *
 */

class ViewDetailRequests extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$item = $this->item;
		$info = $item ['email'];
		return $this->defaultHedearCode ("e10pro-hosting-server-requests", $item ['title'], $info);
	}

	public function createDetailContent ()
	{
		$this->addContent(array ('type' => 'text', 'subtype' => 'code', 'text' => $this->item['requestData']));
	}
}



}