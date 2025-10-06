<?php

namespace hosting\core;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Utils\Utils, \Shipard\Table\DbTable;


/**
 * Class TableServers
 */
class TableServers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.servers', 'hosting_core_servers', 'Servery');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if ($recData['id'] == '')
			$recData ['id'] = 'b' . base_convert (mt_rand (1000, 9999), 10, 35);
		if ($recData['gid'] == '')
			$recData ['gid'] = Utils::createRecId($recData, '!06z');
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
		$icon = $this->app->cfgItem('hosting.core.servers.serverRoles.'.$recData['serverRole'].'.icon', '');
		if ($icon !== '')
			return $icon;
		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewServers
 */
class ViewServers extends TableView
{
	var $serverStats = [];

	var $serverCreateDSTypes;

	public function init ()
	{
		$this->serverCreateDSTypes = $this->app()->cfgItem('hosting.core.serverCreateDSTypes');
		parent::init();

		$this->setMainQueries();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT [servers].*, [owners].[fullName] AS [ownerFullName],';
		array_push($q, ' CONCAT(COALESCE([hwServers].name, [servers].name), ', "'-', ", '[servers].[hwServer], ', "'-', ", '[servers].name) AS serverOrder,');
		array_push($q, ' [webProxies].name AS wpServerName, [webProxies].ipv4 AS wpIPv4, [webProxies].fqdn AS wpServerFqdn');
		array_push($q, ' FROM [hosting_core_servers] AS [servers]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [owners] ON [servers].[owner] = [owners].[ndx]');
		array_push($q, ' LEFT JOIN [hosting_core_servers] AS [hwServers] ON [servers].[hwServer] = [hwServers].[ndx]');
		array_push($q, ' LEFT JOIN [hosting_core_servers] AS [webProxies] ON [servers].[webProxyServer] = [webProxies].[ndx]');
		array_push($q, ' WHERE 1');

		if ($fts != '')
		{
			array_push ($q, ' AND ([servers].[name] LIKE %s OR [servers].[fqdn] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');
			$this->queryMain ($q, '[servers].', ['[servers].[name]', '[servers].[ndx]']);
		}
		else
		{
			$this->queryMain ($q, '[servers].', ['serverOrder', '[servers].[ndx]']);
		}

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = [['text' => $item['name'], 'class' => ''], ];
		$listItem ['t2'] = [];

		$fts = $this->fullTextSearch ();

		if ($item['hwMode'] && $fts === '')
			$listItem['level'] = 1;

		$listItem ['i1'] = ['text' => '#'.$item['gid'], 'class' => 'id', 'suffix' => $item['ndx'], 'prefix' => $item['fqdn']];

		$ipsLabels = [];
		if ($item['ipv4'] !== '')
			$ipsLabels[] = ['text' => 'ipv4', 'suffix' => $item['ipv4'], 'icon' => 'icon-globe', 'class' => ''];
		if ($item['ipv6'] !== '')
			$ipsLabels[] = ['text' => 'ipv6', 'suffix' => $item['ipv6'], 'icon' => 'icon-globe', 'class' => ''];

		if ($item['wpServerName'])
			$ipsLabels[] = ['text' => $item['wpServerName'], 'suffix' => $item['wpIPv4'], 'icon' => 'system/iconGlobe', 'class' => 'label label-default'];
		$listItem ['t3'] = [];

		if (count($ipsLabels))
			$listItem ['t2'] = $ipsLabels;

		if ($item['dsCreateDemo'] != 0)
		{
			$cds = $this->serverCreateDSTypes[$item['dsCreateDemo']];
			$listItem ['t3'][] = ['text' => 'DEMO: '.$cds['fn'], 'icon' => 'system/iconDatabase', 'class' => 'label label-info'];
		}
		if ($item['dsCreateProduction'] != 0)
		{
			$cds = $this->serverCreateDSTypes[$item['dsCreateProduction']];
			$listItem ['t3'][] = ['text' => 'PROD: '.$cds['fn'], 'icon' => 'system/iconDatabase', 'class' => 'label label-info'];
		}

		$props3 = [];
		if ($item['ownerFullName'])
			$props3[] = ['text' => $item['ownerFullName'], 'icon' => 'system/iconUser'];

		if (count($props3))
			$listItem ['t3'] = array_merge($listItem ['t3'], $props3);

		if (!count($listItem ['t2']))
			$listItem ['t2'] = ' ';

		return $listItem;
	}
}


/**
 * Class ViewDetailServer
 */
class ViewDetailServer extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('hosting.core.libs.dc.DocumentCardServer');
	}
}


/**
 * Class ViewDetailServerUpdownIo
 */
class ViewDetailServerUpdownIo extends TableViewDetail
{
	public function createDetailContent ()
	{
		if ($this->item['updownIOId'] !== '')
		{
			$url = 'https://updown.io/'.$this->item['updownIOId'];
			$this->addContent(['type' => 'url', 'url' => $url, 'fullsize' => 1]);
		}
		else
		{
			$this->addContent(['type' => 'line', 'line' => ['text' => 'Monitoring není nastaven...']]);
		}
	}
}

/**
 * Class ViewDetailServerNetdata
 */
class ViewDetailServerNetdata extends TableViewDetail
{
	public function createDetailContent ()
	{
		if ($this->item['netdataUrl'] !== '')
		{
			$url = $this->item['netdataUrl'];
			$this->addContent(['type' => 'url', 'url' => $url, 'fullsize' => 1]);
		}
		else
		{
			$this->addContent(['type' => 'line', 'line' => ['text' => 'Monitoring není nastaven...']]);
		}
	}
}


/**
 * Class FormServer
 */
class FormServer extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('name');
			$this->addColumnInput ('serverRole');
			$this->addColumnInput ('id');
			$this->addColumnInput ('gid');
			$this->addColumnInput ('fqdn');

			if ($this->recData['serverRole'] === 0)
			{
				$this->addSeparator(self::coH3);
				$this->addColumnInput ('dsCreateDemo');
				$this->addColumnInput ('dsCreateProduction');
			}

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('ipv4');
			$this->addColumnInput ('ipv6');

			if ($this->recData['serverRole'] <= 1)
				$this->addColumnInput ('webProxyServer');

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('hwMode');
			if ($this->recData['hwMode'])
			{
				$this->addColumnInput ('hwServer');
				$this->addColumnInput ('vmId');
			}

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('updownIOId');
			$this->addColumnInput ('beszelUrl');
			$this->addColumnInput ('netdataUrl');

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('owner');

		$this->closeForm ();
	}
}
