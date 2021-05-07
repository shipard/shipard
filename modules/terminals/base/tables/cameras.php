<?php

namespace terminals\base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableCameras
 * @package terminals\base
 */
class TableCameras extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('terminals.base.cameras', 'terminals_base_cameras', 'Kamery');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$cameras = [];
		$rows = $this->app()->db->query ('SELECT * from [terminals_base_cameras] WHERE [docState] != 9800 ORDER BY [id], [name], [ndx]');

		foreach ($rows as $r)
		{
			$cam = ['ndx' => $r['ndx'], 'id' => $r['ndx'], 'name' => $r ['name'], 'streamURL' => $r ['streamURL'], 'localServer' => $r ['localServer']];

			// -- sensors
			$sensorsRows = $this->app()->db->query (
					'SELECT doclinks.dstRecId FROM [e10_base_doclinks] as doclinks',
					' WHERE doclinks.linkId = %s', 'terminals-base-cameras-sensors',
					' AND doclinks.srcRecId = %i', $r['ndx']
			);
			foreach ($sensorsRows as $sensor)
				$cam['sensors'][] = $sensor['dstRecId'];

			$cameras [$r['ndx']] = $cam;
		}

		// -- save to file
		$cfg ['e10']['terminals']['cameras'] = $cameras;
		file_put_contents(__APP_DIR__ . '/config/_terminals.cameras.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewCameras
 * @package terminals\base
 */
class ViewCameras extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [terminals_base_cameras]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [name] LIKE %s', '%'.$fts.'%',
					' OR [id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormCamera
 * @package terminals\base
 */
class FormCamera extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('name');
			$this->addColumnInput ('streamURL');
			$this->addColumnInput ('localServer');
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}

