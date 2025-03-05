<?php

namespace e10pro\fp;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class TableDownloadInvites
 */
class TableDownloadInvites extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.fp.downloadInvites', 'e10pro_fp_downloadInvites', 'Výzvy ke stažení');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		if (isset ($recData['uid']) && $recData['uid'] === '')
		{
			$recData['uid'] = Utils::createToken(60, FALSE, TRUE);
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $this->downloadUrl($recData)];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function downloadUrl($recData)
	{
		$storageRecData = $this->app()->loadItem($recData['storage'], 'e10pro.fp.storages');
		if (!$storageRecData)
			return 'invalid-storage';
		$portalRecData = $this->app()->loadItem($storageRecData['filePortal'], 'e10pro.fp.filePortals');
		if (!$portalRecData)
			return 'invalid-portal';

		$url = $portalRecData['startUrl'];
		$url .= 'arq/fpid/'.$recData['uid'];

		return $url;
	}
}


/**
 * class ViewDownloadInvites
 */
class ViewDownloadInvites extends TableView
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

//		$listItem ['storage'] = $item['storageFullName'];
//		$listItem ['user'] = $item['userFullName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [di].* ');
		array_push ($q, ', [storages].[fullName] AS [storageFullName]');
    //array_push ($q, ', [users].[fullName] AS [userFullName]');
		array_push ($q, ' FROM [e10pro_fp_downloadInvites] AS [di]');
		array_push ($q, ' LEFT JOIN [e10pro_fp_storages] AS [storages] ON [di].[storage] = [storages].[ndx]');
    //array_push ($q, ' LEFT JOIN [e10_users_users] AS [users] ON [di].[user] = [users].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [storages].[fullName] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [users].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[di].', ['[storages].[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormDownloadInvite
 */
class FormDownloadInvite extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('storage');
			$this->addColumnInput ('uid');

			$this->addColumnInput('filePath');
			$this->addColumnInput('baseFileName');
			$this->addColumnInput('email');
			$this->addColumnInput('authorUser');
			//$this->addColumnInput('created');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailDownloadInvite
 */
class ViewDetailDownloadInvite extends TableViewDetail
{
}
