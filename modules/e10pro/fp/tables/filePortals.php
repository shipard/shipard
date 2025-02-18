<?php

namespace e10pro\fp;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class TableFilePortals
 */
class TableFilePortals extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.fp.filePortals', 'e10pro_fp_filePortals', 'Souborové portály');
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

	public function saveConfig ()
	{
		$list = [];
		$rows = $this->app()->db->query ('SELECT * from [e10pro_fp_filePortals] WHERE [docState] != 9800 ORDER BY [fullName]');

		foreach ($rows as $r)
    {
			$list [$r['uid']] = [
        'ndx' => $r ['ndx'],
				'uid' => $r ['uid'],
        'fn' => $r ['fullName'],
        'sn' => $r ['shortName'],
				'rf' => $r ['rootFolder'],
      ];
    }

		$cfg ['e10pro']['fp']['filePortals'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.fp.filePortals.json', Json::lint ($cfg));
	}

	public function loadUsersPortals($userNdx)
	{
		$portals = [];
		$q = [];
		array_push($q, 'SELECT [su].*,');
		array_push($q, ' [storages].[filePortal] AS [portalNdx],');
		array_push($q, ' [fp].fullName AS [portalFullName], [fp].uid AS [portalUId]');
		array_push($q, ' FROM [e10pro_fp_storagesUsers] AS [su]');
		array_push($q, ' LEFT JOIN [e10pro_fp_storages] AS [storages] ON [su].[storage] = [storages].ndx');
		array_push($q, ' LEFT JOIN [e10pro_fp_filePortals] AS [fp] ON [storages].[filePortal] = [fp].ndx');
		array_push($q, ' WHERE [su].[user] = %i', $userNdx);
		array_push($q, ' AND [su].[docState] = %i', 4000);

		$rows = $this->app()->db->query($q);
		foreach ($rows as $r)
		{
			$portalNdx = $r['portalNdx'];
			$storageNdx = $r['storage'];
			if (!isset($portals[$portalNdx]))
			{
				$portals[$portalNdx] = [
					'portalFullName' => $r['portalFullName'],
					'portalUId' => $r['portalUId'],
					'storages' => [],
				];
			}
			$portals[$portalNdx]['storages'][$storageNdx] = ['test' => 1];
		}

		return $portals;
	}
}


/**
 * class ViewFilePortals
 */
class ViewFilePortals extends TableView
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
		$listItem ['i1'] = ['text' => $item['uid'], 'class' => 'id'];

    $listItem ['t1'] = $item['fullName'];
		$listItem['t2'] = [];

		$listItem['t2'][] = ['text' => $item['rootFolder'], 'class' => 'label label-default', 'icon' => 'user/folder'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [fp].*');
    array_push ($q, ' FROM [e10pro_fp_filePortals] AS [fp]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND (fp.[fullName] LIKE %s OR fp.[shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '[fp].', ['fullName', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * class FormFilePortal
 */
class FormFilePortal extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('rootFolder');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailFilePortal
 */
class ViewDetailFilePortal extends TableViewDetail
{
}
