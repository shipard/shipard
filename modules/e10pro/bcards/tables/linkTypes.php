<?php

namespace e10pro\bcards;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Json;


/**
 * class TableLinkTypes
 */
class TableLinkTypes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.bcards.linkTypes', 'e10pro_bcards_linkTypes', 'Druhy odkazů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10pro_bcards_linkTypes] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
			$list [$r['ndx']] = ['ndx' => $r ['ndx'], 'id' => $r ['id'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'iconFA' => $r['iconFA']];

		// -- save to file
		$cfg ['e10pro']['bcards']['linkTypes'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.bcards.linkTypes.json', Json::lint ($cfg));
	}
}


/**
 * class ViewLinkTypes
 */
class ViewLinkTypes extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [lt].* ');
    array_push ($q, ' FROM [e10pro_bcards_linkTypes] AS [lt]');
    array_push ($q, '');
    array_push ($q, '');
    array_push ($q, '');
    array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
    {
      array_push ($q, 'AND (');
			array_push ($q, '[fullName] LIKE %s ', '%'.$fts.'%');
      array_push ($q, ')');
    }

		$this->queryMain ($q, '[lt].', ['[fullName]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormLinkType
 */
class FormLinkType extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('id');
      $this->addColumnInput ('iconFA');
      $this->addColumnInput ('order');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailLinkType
 */
class ViewDetailLinkType extends TableViewDetail
{
}
