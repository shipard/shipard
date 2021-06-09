<?php

namespace mac\lan;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableUnknowns
 * @package mac\lan
 */
class TableUnknowns extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.unknowns', 'mac_lan_unknowns', 'Neznámé');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = array ('class' => 'title', 'value' => $recData ['fullName']);

		return $h;
	}
}


/**
 * Class ViewUnknowns
 * @package mac\lan
 */
class ViewUnknowns extends TableView
{
	public function init ()
	{
		parent::init();

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'done', 'title' => 'Vyřešeno'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		$props = [];

		$props[] = ['icon' => 'system/actionPlay', 'text' => utils::datef($item['dateCreate'], '%d, %T')];
		$props[] = ['icon' => 'icon-fast-forward', 'text' => utils::datef($item['dateTouch'], '%d, %T')];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [mac_lan_unknowns] WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s)", '%'.$fts.'%');

		// -- active
		if ($mainQuery === 'active' || $mainQuery === '')
			array_push ($q, ' AND [docStateMain] < 2');

		// -- done
		if ($mainQuery === 'done')
			array_push ($q, ' AND [docStateMain] = 2');

		// trash
		if ($mainQuery === 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		array_push ($q, ' ORDER BY [fullName] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class FormUnknown
 * @package mac\lan
 */
class FormUnknown extends TableForm
{
	public function renderForm ()
	{
		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('ip');
			$this->addColumnInput ('mac');
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailUnknown
 * @package mac\lan
 */
class ViewDetailUnknown extends TableViewDetail
{
	public function createDetailContent ()
	{
		$info = [];

		$info[] = ['p' => 'IP adresa', 'v' => $this->item['ip']];

		$title = [['icon' => 'icon-frown-o', 'text' => 'Neznámé síťové zařízení', 'suffix' => '#'.$this->item['ndx']]];
		$title[] = [
				'type' => 'action', 'action' => 'addwizard', 'table' => 'mac.lan.devices',
				'text' => 'Přidat zařízení', 'data-class' => 'mac.lan.AddDeviceWizard', 'icon' => 'system/actionAdd',
				'class' => 'pull-right', 'actionClass' => 'btn-xs',
				'data-addparams' => 'unknownNdx='.$this->item['ndx']
		];

		$h = ['p' => ' ', 'v' => ''];
		$this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'title' => $title,
				'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
	}
}
