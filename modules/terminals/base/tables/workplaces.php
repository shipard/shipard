<?php

namespace terminals\base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableWorkplaces
 * @package terminals\base
 */
class TableWorkplaces extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('terminals.base.workplaces', 'terminals_base_workplaces', 'Pracoviště');
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
		$workplaces = [];
		$devices = [];

		$rows = $this->app()->db->query ('SELECT * FROM [terminals_base_workplaces] WHERE [docState] != 9800 ORDER BY [id], [ndx]');

		foreach ($rows as $r)
		{
			$w = [
					'ndx' => $r['ndx'], 'id' => $r['id'], 'name' => $r ['name'], 'users' => [],
					'cashBox' => $r['cashBox'], 'centre' => $r['centre'], 'useTerminal' => $r['useTerminal'],
					'gid' => $r['gid'],
					'printers' => ['default' => $r['printerDefault'], 'pos' => $r['printerPOS'], 'labels' => $r['printerLabels'],
					'devices' => [], 'allowedFrom' => []]
			];

			// -- allowed from
			$af = explode(' ', $r['allowedFrom']);
			foreach ($af as $afIP)
				$w['allowedFrom'][] = trim($afIP);

			// -- workplace users
			$usersRows = $this->app()->db->query (
					'SELECT doclinks.dstRecId FROM [e10_base_doclinks] as doclinks',
					' WHERE doclinks.linkId = %s', 'terminals-base-workplaces-users',
					' AND doclinks.srcRecId = %i', $r['ndx']
			);
			foreach ($usersRows as $user)
				$w['users'][] = $user['dstRecId'];

			// -- workplace devices
			$devicesRows = $this->app()->db->query (
					'SELECT doclinks.dstRecId, devices.id as deviceId, devices.name as deviceName FROM [e10_base_doclinks] as doclinks',
					' LEFT JOIN e10_base_devices AS devices ON doclinks.dstRecId = devices.ndx',
					' WHERE doclinks.linkId = %s', 'terminals-base-workplaces-devices',
					' AND doclinks.srcRecId = %i', $r['ndx']
			);
			foreach ($devicesRows as $device)
			{
				$w['devices'][] = $device['deviceId'];
			}

			// -- purchase cameras
			$usersRows = $this->app()->db->query (
				'SELECT doclinks.dstRecId FROM [e10_base_doclinks] as doclinks',
				' WHERE doclinks.linkId = %s', 'terminals-workplaces-purchase-cameras',
				' AND doclinks.srcRecId = %i', $r['ndx']
			);
			foreach ($usersRows as $user)
				$w['purchaseCameras'][] = $user['dstRecId'];

			$workplaces [$r['ndx']] = $w;
		}

		// save to file
		$cfg ['e10']['workplaces'] = $workplaces;
		file_put_contents(__APP_DIR__ . '/config/_terminals.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewWorkplaces
 * @package terminals\base
 */
class ViewWorkplaces extends TableView
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
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [terminals_base_workplaces]';
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

		$this->queryMain ($q, '', ['[name]', '[id]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormWorkplace
 * @package terminals\base
 */
class FormWorkplace extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('name');
			$this->addColumnInput ('id');
			$this->addColumnInput ('cashBox');
			$this->addColumnInput ('centre');
			$this->addColumnInput ('useTerminal');
			$this->addColumnInput ('printerDefault');
			$this->addColumnInput ('printerPOS');
			$this->addColumnInput ('printerLabels');
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
			$this->addColumnInput ('allowedFrom');

			$this->addColumnInput ('gid');
		$this->closeForm ();
	}
}

