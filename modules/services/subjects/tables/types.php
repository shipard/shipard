<?php

namespace services\subjects;



use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableTypes
 * @package services\subjects
 */
class TableTypes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjects.types', 'services_subjects_types', 'Typy subjektů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		// -- properties
		$tablePropDefs = new \E10\Base\TablePropdefs($this->app());
		$cfg ['services']['subjects']['properties'] = $tablePropDefs->propertiesConfig($this->tableId());
		file_put_contents(__APP_DIR__ . '/config/_services.subjects.properties.json', utils::json_lint(json_encode ($cfg)));
	}
}


/**
 * Class ViewTypes
 * @package services\subjects
 */
class ViewTypes extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

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

	function decorateRowX (&$item)
	{
		$item ['t2'] = $this->propgroups [$item ['pk']];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * from [services_subjects_types] WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([fullName] LIKE %s OR [id] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');


		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailType
 * @package services\subjects
 */
class ViewDetailType extends TableViewDetail
{
}


/**
 * Class FormType
 * @package services\subjects
 */
class FormType extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('id');
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}

