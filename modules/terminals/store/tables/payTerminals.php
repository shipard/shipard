<?php

namespace terminals\store;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TablePayTerminals
 * @package terminals\store
 */
class TablePayTerminals extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('terminals.store.payTerminals', 'terminals_store_payTerminals', 'Platební terminály');
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
		$list = [];
		$rows = $this->app()->db->query ('SELECT * from [terminals_store_payTerminals] WHERE [docState] != 9800 ORDER BY [id], [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$trm = ['ndx' => $r['ndx'], 'id' => $r['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'bt' => $r ['buttonText'], 'pb' => $r['personBalance']];
			$list [$r['ndx']] = $trm;
		}

		// -- save to file
		$cfg ['e10']['terminals']['payTerminals'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_terminals.payTerminals.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewPayTerminals
 * @package terminals\store
 */
class ViewPayTerminals extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT terminals.* FROM [terminals_store_payTerminals] AS [terminals]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' terminals.[fullName] LIKE %s', '%'.$fts.'%',
				' OR terminals.[id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'terminals.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormPayTerminal
 * @package terminals\store
 */
class FormPayTerminal extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('buttonText');
			$this->addColumnInput ('id');
			$this->addColumnInput ('personBalance');
		$this->closeForm ();
	}
}

