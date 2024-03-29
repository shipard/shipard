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
		array_push($q, ' CONCAT(COALESCE([hwServers].name, [servers].name), [servers].name) AS serverOrder');
		array_push($q, ' FROM [hosting_core_servers] AS [servers]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [owners] ON [servers].[owner] = [owners].[ndx]');
		array_push($q, ' LEFT JOIN [hosting_core_servers] AS [hwServers] ON [servers].[hwServer] = [hwServers].[ndx]');
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
		$listItem ['t2'] = [
				['text' => $item['id'], 'class' => 'label label-default'],
		];

		$fts = $this->fullTextSearch ();

		if ($item['hwMode'] && $fts === '')
			$listItem['level'] = 1;

		if ($item['osVerId'] !== '')
			$listItem ['t2'][] = ['text' => $item['osVerId'], 'class' => 'label label-default'];

		if ($item['shipardServerVerId'] !== '')
			$listItem ['t2'][] = ['text' => $item['shipardServerVerId'], 'class' => 'label label-default'];


		if ($item['dsCreateDemo'] != 0)
		{
			$cds = $this->serverCreateDSTypes[$item['dsCreateDemo']];
			$listItem ['t2'][] = ['text' => 'DEMO: '.$cds['fn'], 'icon' => 'system/iconDatabase', 'class' => 'label label-info'];	
		}
		if ($item['dsCreateProduction'] != 0)
		{
			$cds = $this->serverCreateDSTypes[$item['dsCreateProduction']];
			$listItem ['t2'][] = ['text' => 'PROD: '.$cds['fn'], 'icon' => 'system/iconDatabase', 'class' => 'label label-info'];	
		}

		//$listItem ['t2'][] = ['text' => $item['fqdn'], 'class' => '', 'suffix' => $item['ipv4']];

		$listItem ['i1'] = ['text' => '#'.$item['gid'], 'class' => 'id', 'suffix' => $item['ndx']];

		$props3 = [];
		if ($item['ownerFullName'])
			$props3[] = ['text' => $item['ownerFullName'], 'icon' => 'system/iconUser'];

		if (count($props3))
			$listItem ['t3'] = $props3;

		return $listItem;
	}

	function decorateRow (&$item)
	{
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

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('dsCreateDemo');
			$this->addColumnInput ('dsCreateProduction');

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('ipv4');
			$this->addColumnInput ('ipv6');

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('hwMode');
			if ($this->recData['hwMode'])
			{
				$this->addColumnInput ('hwServer');
				$this->addColumnInput ('vmId');
			}	

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('updownIOId');
			$this->addColumnInput ('netdataUrl');

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('owner');

		$this->closeForm ();
	}
}
