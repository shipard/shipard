<?php

namespace E10\Base;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;

/**
 * Class TableNotifications
 * @package E10\Base
 */
class TableNotifications extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.notifications', 'e10_base_notifications', 'Oznámení');
	}
}


/**
 * Class ViewNotifications
 * @package E10\Base
 */
class ViewNotifications extends TableView
{
	public function init ()
	{
		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['subject'];
		$listItem ['i1'] = $item['text'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT ntf.*, persons.fullName as personName from [e10_base_notifications] AS ntf';
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON ipaddr.person = persons.ndx');
		array_push ($q, ' WHERE 1');

		/*
		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([title] LIKE %s OR [ipaddr] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND ipaddr.[docStateMain] < 4");

		// -- trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND ipaddr.[docStateMain] = 4");
*/
		array_push ($q, ' ORDER BY [created], ntf.[ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewNotificationsCentre
 * @package E10\Base
 */
class ViewNotificationsCentre extends TableView
{
	static $ntfTypeClasses = [
		0 => 'e10-state-new', 1 => 'e10-state-confirmed', 2 => 'e10-state-done',
		4 => 'e10-warning1', 5 => '', 90 => 'e10-error', 91 => 'e10-state-new', 92 => 'e10-state-new'
	];

	public function init ()
	{
		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'readed', 'title' => 'Přečtené'];
		$this->setMainQueries ($mq);

		$this->setPaneMode();
		$this->objectSubType = TableView::vsDetail;

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['subject'];
		$listItem ['i1'] = $item['text'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$q [] = 'SELECT ntf.*, persons.fullName as personName from [e10_base_notifications] AS ntf';
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON ntf.personSrc = persons.ndx');
		array_push ($q, ' WHERE ntf.[personDest] = %i', $this->app()->user()->data ('id'));
		array_push ($q, ' AND ntf.[state] = 0');
		array_push ($q, ' ORDER BY [created] DESC, ntf.[ndx] DESC' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	function renderPane (&$item)
	{
		$icon = ($item['icon']) ? $item['icon'] : 'icon-bell-o';
		$ntfTypeClass = isset(self::$ntfTypeClasses[$item['ntfType']]) ? self::$ntfTypeClasses[$item['ntfType']] : '';

		$title = [];
		if ($item['ntfTypeName'] !== '')
			$title[] = ['class' => 'pull-right tag '.$ntfTypeClass, 'text' => $item['ntfTypeName']];
		$title[] = ['class' => 'h2', 'text' => $item['subject']];

		$item ['pane'] = ['info' => [], 'class' => 'e10-pane-vitem', 'icon' => $icon];
		$item ['pane']['info'][] = ['value' => $title];

		$props = [];

		if ($item['personName'])
			$props[] = ['class' => 'tag', 'icon' => 'icon-chevron-left', 'text' => $item['personName']];

		$item ['pane']['info'][] = ['value' => $props];

		// -- commands
		$cmds = [];
		$cmds[] = ['text' => '', 'class' => 'block'];
		if ($item['objectType'] === 'document')
		{
			$docNdx = (isset($item ['recIdMain']) && $item ['recIdMain']) ? $item ['recIdMain'] : $item ['recId'];
			$cmds[] = [
				'class' => 'pull-right', 'text' => 'Otevřít', 'icon' => 'system/actionOpen', 'docAction' => 'edit',
				'table' => $item['tableId'], 'pk' => $docNdx, 'type' => 'button', 'actionClass' => 'btn btn-xs btn-primary'
			];
		}

		$cmds[] = [
			'type' => 'widget', 'action' => 'drop-'.$item['ndx'], 'text' => 'Přečteno', 'icon' => 'icon-eye-slash',
			'actionClass' => 'btn btn-xs btn-default', 'class' => 'pull-right'
		];


		$item ['pane']['info'][] = ['class' => 'commands', 'value' => $cmds];
	}

	function createStaticContent()
	{
		/*
		$activities = new \lib\wkf\RunningActivities($this->app());
		$activities->run();
		if (count($activities->worksRecs))
			$this->objectData ['staticContent'] = $activities->createCode();
		*/
	}
}
