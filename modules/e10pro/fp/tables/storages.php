<?php

namespace e10pro\fp;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class TableStorages
 */
class TableStorages extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.fp.storages', 'e10pro_fp_storages', 'Úložiště');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		if (isset ($recData['uid']) && $recData['uid'] === '')
		{
			$recData['uid'] = Utils::createToken(8, FALSE, TRUE);
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewStorages
 */
class ViewStorages extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];

    $listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => $item['uid'], 'class' => 'id'];

		$listItem['t2'] = [];

		$listItem['t2'][] = ['text' => $item['fpShortName'], 'class' => 'label label-default'];
		$listItem['t2'][] = ['text' => $item['rootFolder'], 'class' => 'label label-default', 'icon' => 'user/folder'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [stors].*');
		array_push ($q, ', [fp].[shortName] AS [fpShortName]');
    array_push ($q, ' FROM [e10pro_fp_storages] AS [stors]');
		array_push ($q, ' LEFT JOIN [e10pro_fp_filePortals] AS [fp] ON [stors].[filePortal] = [fp].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND (stors.[fullName] LIKE %s OR fp.[shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '[stors].', ['fullName', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * class FormStorage
 */
class FormStorage extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('filePortal');
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('rootFolder');
			$this->addSeparator(self::coH4);
			$this->addColumnInput ('emailForSendDownloads');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailStorage
 */
class ViewDetailStorage extends TableViewDetail
{
}
