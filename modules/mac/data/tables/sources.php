<?php

namespace mac\data;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableSources
 * @package mac\data
 */
class TableSources extends DbTable
{
	CONST dstUnknown = 0, dstNetData = 1, dstMqtt = 2, dstInfluxDB = 3;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.data.sources', 'mac_data_sources', 'Zdroje dat');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewSources
 * @package mac\data
 */
class ViewSources extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$sourceTypes = $this->table->columnInfoEnum ('sourceType', 'cfgText');

		$listItem ['t2'] = [];

		$listItem ['t2'][] = ['text' => $sourceTypes [$item ['sourceType']], 'class' => 'label label-default'];

		if ($item['serverId'])
			$listItem ['t2'][] = ['text' => $item ['serverId'], 'class' => 'label label-default', 'icon' => ($item['serverKind'] == 70) ? 'icon-arrows-alt' : 'icon-server'];

		if ($item['url'] !== '')
			$listItem ['t2'][] = ['text' => $item ['url'], 'class' => 'label label-default', 'icon' => 'icon-globe'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT dataSources.*, servers.fullName AS serverFullName, servers.id AS serverId, servers.deviceKind AS serverKind';
		array_push ($q, ' FROM [mac_data_sources] AS dataSources');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS [servers] ON dataSources.server = [servers].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' dataSources.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR dataSources.[url] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'dataSources.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormSource
 * @package mac\data
 */
class FormSource extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Data', 'icon' => 'icon-database'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('sourceType');
					$this->addColumnInput ('fullName');
					if ($this->recData['sourceType'] == TableSources::dstNetData)
					{
						$this->addColumnInput ('server');
						$this->addColumnInput('url');
					}
					elseif ($this->recData['sourceType'] == TableSources::dstInfluxDB)
					{
						$this->addColumnInput('url');
						$this->addColumnInput ('organizationId');
						$this->addColumnInput ('bucketId');
						$this->addColumnInput ('token');
					}

				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSource
 * @package mac\data
 */
class ViewDetailSource extends TableViewDetail
{
}

