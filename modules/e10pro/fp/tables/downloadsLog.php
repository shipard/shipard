<?php

namespace e10pro\fp;

use \Shipard\Viewer\TableViewGrid, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
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
class ViewDownloadsLog extends TableViewGrid
{
	public function init ()
	{
		parent::init();

		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;

		$g = [
      'tsd' => 'Datum a čas',
			'storage' => 'Úložiště',
			'fp' => 'Složka',
      'fn' => 'Soubor',
			'source' => 'Staženo z',
			'ipAddress' => 'IP adresa',
		];

		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem['tsd'] = Utils::datef($item['tsDownload'], '%D %T');
		$listItem['fp'] = $item['filePath'];
		$listItem['fn'] = $item['baseFileName'];
		$listItem['storage'] = $item['storageFullName'];
		$listItem['ipAddress'] = $item['ipAddress'];

		if ($item['invite'])
		{
			$listItem['source'] = ['text' => $item['inviteEmail'], 'suffix' => substr($item['inviteUid'], 0, 4).'...'.substr($item['inviteUid'], -4), 'icon' => 'tables/e10pro.fp.downloadInvites'];
		}
		elseif ($item['user'])
		{
			$listItem['source'] = ['text' => $item['userFullName'], 'icon' => 'system/iconUser'];
		}
		else
		{
			$listItem['source'] = '???';
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [dl].* ');
		array_push ($q, ', [storages].[fullName] AS [storageFullName]');
    array_push ($q, ', [users].[fullName] AS [userFullName]');
		array_push ($q, ', [invites].[email] AS [inviteEmail], [invites].[uid] AS [inviteUid]');
		array_push ($q, ' FROM [e10pro_fp_downloadsLog] AS [dl]');
		array_push ($q, ' LEFT JOIN [e10pro_fp_storages] AS [storages] ON [dl].[storage] = [storages].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_users_users] AS [users] ON [dl].[user] = [users].[ndx]');
		array_push ($q, ' LEFT JOIN [e10pro_fp_downloadInvites] AS [invites] ON [dl].[invite] = [invites].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [storages].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [dl].[baseFileName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [users].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [dl].[ipAddress] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [invites].[email] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

    array_push($q, ' ORDER BY [dl].[tsDownload] DESC, [dl].[ndx] DESC');
    array_push($q, $this->sqlLimit());
		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
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
		$this->readOnly = 1;

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
