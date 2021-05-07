<?php

namespace mac\sw;


use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableCategories
 * @package mac\sw
 */
class TableCategories extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.sw.categories', 'mac_sw_categories', 'Kategorie SW');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['suid']) && $recData['suid'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['suid'] = utils::createRecId($recData, '!05z');
		}
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon($recData, $options);
	}
}


/**
 * Class ViewCategories
 * @package mac\sw
 */
class ViewCategories extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['suid'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [cats].*';
		array_push ($q, ' FROM [mac_sw_categories] AS [cats]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [cats].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [cats].[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'cats.', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormCategory
 * @package mac\sw
 */
class FormCategory extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Kategorie', 'icon' => 'icon-folder'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('order');
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
 * @package mac\sw
 */
class ViewDetailCategory extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
