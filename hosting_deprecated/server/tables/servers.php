<?php

namespace E10pro\Hosting\Server;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \e10\utils, \E10\DbTable;

/**
 * Class TableServers
 * @package E10pro\Hosting\Server
 */
class TableServers extends DbTable
{
	public static $defaultIconSet = ['icon-server', 'system/iconSitemap', 'x-server'];

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.hosting.server.servers", "e10pro_hosting_server_servers", "Servery");
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if ($recData['id'] == '')
			$recData ['id'] = 'b' . base_convert (mt_rand (1000, 9999), 10, 35);
		if ($recData['gid'] == '')
			$recData ['gid'] = mt_rand (10000, 999999).'0'.mt_rand (100000, 9999999);
		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['info'][] = ['class' => 'info', 'value' => [['text'=>$recData ['fqdn']], ['text' => $recData ['id'], 'class' => 'pull-right']]];
		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['name']], ['text' => '#'.$recData ['gid'], 'class' => 'pull-right id']]];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return self::$defaultIconSet [$recData['serverType']];
	}
}


/**
 * Class ViewServers
 * @package E10pro\Hosting\Server
 */
class ViewServers extends TableView
{
	var $serverStats = [];

	public function init ()
	{
		parent::init();
		$this->setMainQueries();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT [servers].*, [owners].[fullName] AS [ownerFullName], [customers].[fullName] AS [customerFullName]';
		array_push($q, ' FROM [e10pro_hosting_server_servers] AS [servers]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [customers] ON [servers].[customer] = [customers].[ndx]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [owners] ON [servers].[owner] = [owners].[ndx]');
		array_push($q, ' WHERE 1');

		if ($fts != '')
			array_push ($q, ' AND ([servers].[name] LIKE %s OR [servers].[fqdn] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '[servers].', ['[servers].[name]', '[servers].[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		// -- server stats
		$serverStats = $this->db()->query ('SELECT * FROM e10pro_hosting_server_serversStats WHERE server IN %in', $this->pks);
		foreach ($serverStats as $r)
			$this->serverStats[$r['server']] = $r->toArray();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = [['text' => $item['name'], 'class' => ''], ];
		$listItem ['t2'] = [
				['text' => $item['id'], 'class' => 'label label-default'],

		];

		if ($item['creatingDataSources'] === 1)
			$listItem ['t2'][] = ['text' => 'Svoje', 'icon' => 'system/iconDatabase', 'class' => 'label label-info'];
		elseif ($item['creatingDataSources'] === 2)
			$listItem ['t2'][] = ['text' => 'Všechny', 'icon' => 'system/iconDatabase', 'class' => 'label label-success'];

		$listItem ['t2'][] = ['text' => $item['fqdn'], 'class' => '', 'suffix' => $item['ipaddress']];

		$listItem ['i1'] = ['text' => '#'.$item['gid'], 'class' => 'id', 'suffix' => $item['ndx']];

		$props3 = [];
		if ($item['ownerFullName'])
			$props3[] = ['text' => $item['ownerFullName'], 'icon' => 'system/iconUser'];
		if ($item['customerFullName'])
			$props3[] = ['text' => $item['customerFullName'], 'icon' => 'icon-building'];

		if (count($props3))
			$listItem ['t3'] = $props3;


		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset($this->serverStats[$item ['pk']]))
		{
			$percents = round(($this->serverStats[$item ['pk']]['diskFreeSpace'] / $this->serverStats[$item ['pk']]['diskTotalSpace'])*100, 1);
			$labelClass = ($percents < 20.0) ? 'label-danger' : 'label-primary';
			$item ['i2'][] = [
					'text' => utils::memf($this->serverStats[$item ['pk']]['diskUsedSpace']).' / '.utils::memf($this->serverStats[$item ['pk']]['diskTotalSpace']),
					'class' => 'pull-right label '.$labelClass, 'icon' => 'icon-hdd-o'
			];
		}
	}
}


/**
 * Class ViewDetailServers
 * @package E10pro\Hosting\Server
 */
class ViewDetailServers extends TableViewDetail
{
}


/**
 * Class FormServer
 * @package E10pro\Hosting\Server
 */
class FormServer extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('name');
			$this->addColumnInput ('fqdn');
			$this->addColumnInput ('id');
			$this->addColumnInput ('gid');
			$this->addColumnInput ('serverType');
			$this->addColumnInput ('creatingDataSources');
			$this->addColumnInput ('ipaddress');
			$this->addColumnInput ('customer');
			$this->addColumnInput ('owner');
		$this->closeForm ();
	}
}

