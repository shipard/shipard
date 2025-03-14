<?php

namespace e10pro\fp;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableDownloadsLog
 */
class TableDownloadsLog extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.fp.downloadsLog', 'e10pro_fp_downloadsLog', 'Log stahování');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['baseFileName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['ipAddress']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewDownloadsLog
 */
class ViewDownloadsLog extends TableView
{
	public function init ()
	{
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem['t1'] = ['text' => $item['storageFullName'], 'suffix' => $item['email']];
		$listItem['t2'] = ['text' => $item['userFullName'], 'icon' => 'system/iconUser'];
		$listItem['i2'] = [['text' => Utils::datef($item['tsDownload'], '%D%t'), 'icon' => 'system/actionDownload']];

		$listItem['t3'] = ['text' => $item['filePath'].'/'.$item['baseFileName'], 'icon' => 'system/iconFile'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [dl].* ');
		array_push ($q, ', [storages].[fullName] AS [storageFullName]');
    array_push ($q, ', [users].[fullName] AS [userFullName]');
		array_push ($q, ' FROM [e10pro_fp_downloadsLog] AS [dl]');
		array_push ($q, ' LEFT JOIN [e10pro_fp_storages] AS [storages] ON [dl].[storage] = [storages].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_users_users] AS [users] ON [dl].[user] = [users].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [storages].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [users].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

    array_push($q, ' ORDER BY [dl].[tsDownload] DESC, [dl].[ndx] DESC');
    array_push($q, $this->sqlLimit());
		$this->runQuery ($q);
	}
}


/**
 * class FormDownloadLog
 */
class FormDownloadLog extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('storage');
			$this->addColumnInput('filePath');
			$this->addColumnInput('baseFileName');
			$this->addColumnInput('user');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailDownloadLog
 */
class ViewDetailDownloadLog extends TableViewDetail
{
}
