<?php

namespace e10pro\bcards;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableOrgs
 */
class TableOrgs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.bcards.orgs', 'e10pro_bcards_orgs', 'Organizace');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewOrgs
 */
class ViewOrgs extends TableView
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

    array_push ($q, 'SELECT [orgs].* ');
    array_push ($q, ' FROM [e10pro_bcards_orgs] AS [orgs]');
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

		$this->queryMain ($q, '[orgs].', ['[fullName]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormOrg
 */
class FormOrg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('webTemplate');
			$this->addColumnInput ('companyBCard');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailOrg
 */
class ViewDetailOrg extends TableViewDetail
{
}
