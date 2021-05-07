<?php

namespace services\subjects;



use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableKinds
 * @package services\subjects
 */
class TableKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjects.kinds', 'services_subjects_kinds', 'Druhy subjektů');
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
		$list [0] = ['ndx' => 0, 'id' => '', 'fullName' => '', 'shortName' => ''];

		$rows = $this->app()->db->query ('SELECT * FROM [services_subjects_kinds] WHERE docState != 9800 ORDER BY [fullName], [ndx]');

		foreach ($rows as $r)
			$list [$r['ndx']] = ['ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName']];

		// save to file
		$cfg ['services']['subjects']['kinds'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_services.subjects.kinds.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewKinds
 * @package services\subjects
 */
class ViewKinds extends TableView
{
	public function init ()
	{
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * from [services_subjects_kinds] WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([fullName] LIKE %s OR [shortName] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');


		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailKind
 * @package services\subjects
 */
class ViewDetailKind extends TableViewDetail
{
	public function createDetailContent ()
	{
		$e = new \lib\content\ItemDetail($this->app());
		$e->load($this->table, $this->item);
		$this->addContent ($e->contentPart);
	}
}


/**
 * Class FormKind
 * @package services\subjects
 */
class FormKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Zařazení', 'icon' => 'icon-list-ol'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
				$this->closeTab ();

				$this->openTab ();
					$this->addList('nomenclature');
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

