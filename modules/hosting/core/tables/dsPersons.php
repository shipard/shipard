<?php

namespace hosting\core;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * class TableDSPersons
 */
class TableDSPersons extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.dsPersons', 'hosting_core_dsPersons', 'Osoby Zdrojů dat');
	}

	public function createHeader ($recData, $options)
	{
		$tablePersons = $this->app()->table ('e10.persons.persons');
		if ($recData['person'])
			$person = $tablePersons->loadItem ($recData['person']);

    $dsRecData = NULL;
		if ($recData['dataSource'])
			$dsRecData = $this->app->loadItem ($recData['dataSource'], 'hosting.core.dataSources');

		$hdr = parent::createHeader ($recData, $options);

		if ($dsRecData)
			$hdr ['info'][] = ['class' => 'info', 'value' => [['text' => $dsRecData ['name'], 'icon' => 'tables/hosting.core.dataSources']]];
		if ($recData['person'])
			$hdr ['info'][] = ['class' => 'info', 'value' => [['text' => $person ['fullName'], 'icon' => $tablePersons->tableIcon($person)]]];

		return $hdr;
	}
}


/**
 * class ViewDetailDSPerson
 */
class ViewDetailDSPerson extends TableViewDetail
{
}


/**
 * class FormDSPerson
 */
class FormDSPerson extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
      $this->addColumnInput ('person');
      $this->addColumnInput ('isAdmin');
      $this->addColumnInput ('isSupport');
      //$this->addColumnInput ('dataSource');
		$this->closeForm ();
	}
}


/**
 * class ViewDSPersons
 */
class ViewDSPersons extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if ($this->queryParam ('dataSource'))
		{
			$this->addAddParam ('dataSource', $this->queryParam ('dataSource'));
		}

		$this->setMainQueries();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['personName'];
		$listItem ['i1'] = ['text' => '#'.$item['personId'], 'class' => 'id'];

		$props = [];
		if ($item['isAdmin'])
			$props[] = ['text' => 'Správce zdroje dat', 'class' => 'label label-default', 'icon' => 'system/actionSettings'];
		if ($item['isSupport'])
			$props[] = ['text' => 'Technická podpora zákazníků', 'class' => 'label label-default', 'icon' => 'system/actionSupport'];
		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT dsPersons.*, persons.fullName AS personName, persons.id AS personId');
		array_push ($q, ' FROM [hosting_core_dsPersons] AS dsPersons');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON dsPersons.person = persons.ndx');

		array_push($q, ' WHERE dsPersons.dataSource = %i', $this->queryParam ('dataSource'));

		if ($fts != '')
			array_push ($q, " AND (persons.[fullName] LIKE %s)", '%'.$fts.'%');

		$this->queryMain ($q, '[dsPersons].', ['[persons].[fullName]', '[dsPersons].[ndx]']);

		$this->runQuery ($q);
	}
}

