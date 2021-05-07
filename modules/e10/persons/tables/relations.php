<?php

namespace e10\persons;


use \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\utils;


/**
 * Class TableRelations
 * @package e10\persons
 */
class TableRelations extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.relations', 'e10_persons_relations', 'Vztahy Osob');
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewRelations
 * @package e10\persons
 */
class ViewRelations extends TableView
{
	var $relationsCategories;

	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
		$this->enableDetailSearch = TRUE;

		$bt = [
			['id' => '0', 'title' => 'Ručně', 'active' => 1],
			['id' => '1', 'title' => 'Automaticky', 'active' => 0],
			['id' => '999', 'title' => 'Vše', 'active' => 0]
		];
		$this->setBottomTabs ($bt);

		$this->relationsCategories = $this->app()->cfgItem ('e10.persons.categories.categories');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['personFullName'];
		$listItem ['i1'] = ['text' => '#'.$item['personId'], 'class' => 'id'];

		$c = $this->relationsCategories[$item['category']];
		$listItem ['t2'] = [['text' => $c['fn']]];

		if ($item['validFrom'])
			$listItem ['t2'][] = ['text' => utils::datef($item['validFrom'], '%D'), 'icon' => 'icon-play'];
		if ($item['validTo'])
			$listItem ['t2'][] = ['text' => utils::datef($item['validTo'], '%D'), 'icon' => 'icon-stop'];

		if ($item['parentPersonFullName'])
		{
			$listItem ['t3'] = [
				['text' => $item['parentPersonFullName']],
				['text' => '#'.$item['parentPersonId'], 'class' => 'id pull-right']
			];
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bt = intval($this->bottomTabId ());

		$q [] = 'SELECT relations.*,';
		array_push ($q, ' persons.fullName AS personFullName, persons.id AS personId,');
		array_push ($q, ' parentPersons.fullName AS parentPersonFullName, parentPersons.id AS parentPersonId');
		array_push ($q, ' FROM [e10_persons_relations] AS relations');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON relations.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS parentPersons ON relations.parentPerson = parentPersons.ndx');
		array_push ($q, ' WHERE 1');

		if ($bt != 999)
			array_push ($q, ' AND [source] = %i', $bt);

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND (persons.[fullName] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, 'relations.', ['persons.[fullName]', 'relations.[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormRelation
 * @package e10\persons
 */
class FormRelation extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Důvod', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('person');
					$this->addColumnInput ('category');
					$this->addColumnInput ('parentPerson');
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailRelation
 * @package e10\persons
 */
class ViewDetailRelation extends TableViewDetail
{
}

