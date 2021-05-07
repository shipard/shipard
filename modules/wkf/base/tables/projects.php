<?php

namespace wkf\base;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableProjects
 * @package wkf\base
 */
class TableProjects extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.base.projects', 'wkf_base_projects', 'Projekty');
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
 * Class ViewProjects
 * @package wkf\base
 */
class ViewProjects extends TableView
{
	public function init ()
	{
		parent::init();

//		$this->objectSubType = TableView::vsDetail;
//		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = intval($this->bottomTabId ());

		$q [] = 'SELECT projects.*';
		array_push ($q, ' FROM [wkf_base_projects] AS [projects]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' projects.[fullName] LIKE %s', '%'.$fts.'%',
				' OR projects.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[projects].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormProject
 * @package wkf\base
 */
class FormProject extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'x-wrench'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput ('icon');
					$this->addColumnInput ('order');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
