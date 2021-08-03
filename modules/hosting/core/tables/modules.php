<?php

namespace hosting\core;
use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableModules
 */
class TableModules extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.modules', 'hosting_core_modules', 'Moduly systému');
	}

	public function saveConfig ()
	{
		$cfgList = [];
		$rows = $this->app()->db->query ('SELECT * FROM [hosting_core_modules] WHERE docState = 4000 ORDER BY [order], [id]');

		foreach ($rows as $r)
		{
			$cfgList [$r['ndx']] = [
				'ndx' => $r ['ndx'], 'id' => $r ['id'],
				'name' => $r ['name'], 'description' => $r ['description'],
				'private' => $r ['private'],
			];
		}

		// -- save to file
		$cfg ['hosting']['core']['modules'] = $cfgList;
		file_put_contents(__APP_DIR__ . '/config/_hosting.core.modules.json', utils::json_lint (json_encode ($cfg)));
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}
}


/**
 * Class ViewModules
 */
class ViewModules extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q[] = 'SELECT * FROM [hosting_core_modules] ';

		array_push($q, ' WHERE 1');

		if ($fts != '')
			array_push ($q, ' AND ([name] LIKE %s OR [id] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");
		if ($mainQuery == 'archive')
			array_push ($q, " AND [docStateMain] = 5");
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		array_push($q, ' ORDER BY [order], [name]');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = $item['id'];
		if ($item['private'])
			$listItem ['t2'] .= ' ' . utils::es ('(Neveřejné)');
		if ($item['order'])
			$listItem ['i2'] = ['icon' => 'system/iconOrder', 'text' => \E10\nf ($item ['order'], 0)];
		return $listItem;
	}
}


/**
 * Class ViewDetailModule
 */
class ViewDetailModule extends TableViewDetail
{
}


/**
 * Class FormModule
 */
class FormModule extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-properties'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('description');
					$this->addColumnInput ('type');
					$this->addColumnInput ('id');
					$this->addColumnInput ('order');
					$this->addColumnInput ('private');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
