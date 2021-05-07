<?php

namespace e10pro\reception;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableForeignersPoliceFiles
 * @package e10pro\reception
 */
class TableForeignersPoliceFiles extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.reception.foreignersPoliceFiles', 'e10pro_reception_foreignersPoliceFiles', 'Soubory pro cizineckou policii');
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'normal', 'value' => ''];

		return $hdr;
	}
}


/**
 * Class ViewForeignersPoliceFiles
 * @package e10pro\reception
 */
class ViewForeignersPoliceFiles extends TableView
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
		$listItem ['t1'] = 'mmmm';

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_reception_foreignersPoliceFiles]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [data] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[dateCreate] DESC', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormForeignerPoliceFile
 * @package e10pro\reception
 */
class FormForeignerPoliceFile extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Údaje', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'Data', 'icon' => 'icon-file-text-o'];

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('accPlace');
					$this->addColumnInput ('dateCreate');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('data', NULL, TableForm::coFullSizeY);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailForeignerPoliceFiles
 * @package e10pro\reception
 */
class ViewDetailForeignerPoliceFiles extends TableViewDetail
{
}

