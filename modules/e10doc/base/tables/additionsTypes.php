<?php

namespace E10Doc\Base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableAdditionsTypes
 * @package E10Doc\Base
 */
class TableAdditionsTypes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.additionsTypes', 'e10doc_base_additionsTypes', 'Typy dodatků dokladů');
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

		$rows = $this->app()->db->query ('SELECT * FROM [e10doc_base_additionsTypes] WHERE [docState] != 9800 ORDER BY [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'], 'id' => $r ['id'], 'dir' => $r ['dir'],
				'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'labelPrefix' => $r ['labelPrefix']
			];

			$list [$r['ndx']] = $item;

		}

		// -- save to file
		$cfg ['e10doc']['additionsTypes'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.additionsTypes.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewAdditionsTypes
 * @package E10Doc\Base
 */
class ViewAdditionsTypes extends TableView
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
		$listItem ['t2'] = $item['shortName'];
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_base_additionsTypes]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortName] LIKE %s', '%'.$fts.'%',
				' OR [id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormAdditionType
 * @package E10Doc\Base
 */
class FormAdditionType extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('dir');
			$this->addColumnInput ('id');
			$this->addColumnInput ('labelPrefix');
		$this->closeForm ();
	}
}

