<?php

namespace e10doc\base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableAdditions
 * @package e10doc\base
 */
class TableAdditions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.additions', 'e10doc_base_additions', 'Dodatků dokladů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['identifier']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	/*
	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10doc_base_additionsTypes] WHERE [docState] != 9800 ORDER BY [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'], 'fullName' => $r ['fullName'],
				'shortName' => $r ['shortName']
			];

			$list [$r['ndx']] = $item;

		}

		// -- save to file
		$cfg ['e10doc']['additionsTypes'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.additionsTypes.json', utils::json_lint (json_encode ($cfg)));
	}
	*/
}


/**
 * Class ViewAdditions
 * @package e10doc\base
 */
class ViewAdditions extends TableView
{
	var $additionsTypes;

	public function init ()
	{
		$this->additionsTypes = $this->app()->cfgItem ('e10doc.additionsTypes');

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$at = $this->additionsTypes[$item['additionType']];


		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $at['fullName'];
		$listItem ['i1'] = $item['rowMark'];
		$listItem ['t2'] = $item['identifier'];
		//$listItem ['t2'] = $item['shortName'];
		//$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT additions.* FROM [e10doc_base_additions] AS additions';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' additions.[identifier] LIKE %s', '%'.$fts.'%', ' OR additions.[rowMark] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'additions.', ['[rowMark], [identifier]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormAddition
 * @package e10doc\base
 */
class FormAddition extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('additionType');
			$this->addColumnInput ('identifier');
			$this->addColumnInput ('rowMark');
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}

