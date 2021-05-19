<?php

namespace e10\persons;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableCategories
 * @package e10pro\gdpr
 */
class TableCategories extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.categories', 'e10_persons_categories', 'Kategorie Osob');
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

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10_persons_categories] WHERE [docState] != 9800 ORDER BY [ndx]');

		foreach ($rows as $r)
		{
			$item = ['ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'type' => $r ['categoryType']];

			$catType = $this->app()->cfgItem ('e10.persons.categories.types.'.$r ['categoryType'], NULL);
			if ($catType)
			{
				if (isset($catType['classId']))
					$item['classId'] = $catType['classId'];

				$item['useOnHuman'] = $catType['useOnHuman'];
				$item['useOnCompany'] = $catType['useOnCompany'];
				$item['needParentPerson'] = $catType['needParentPerson'];
			}

			$list [$r['ndx']] = $item;
		}

		// save to file
		$cfg ['e10']['persons']['categories']['categories'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10.persons.categories.categories.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewCategories
 * @package e10pro\gdpr
 */
class ViewCategories extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT categories.* FROM [e10_persons_categories] AS categories';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s)", '%'.$fts.'%');

		$this->queryMain ($q, 'categories.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormCategory
 * @package e10pro\gdpr
 */
class FormCategory extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('categoryType');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailCategory
 * @package e10pro\gdpr
 */
class ViewDetailCategory extends TableViewDetail
{
}

