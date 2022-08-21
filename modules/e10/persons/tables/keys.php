<?php

namespace e10\persons;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableKeys
 */
class TableKeys extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.keys', 'e10_persons_keys', 'Přístupové klíče');
	}
}


/**
 * class ViewKeys
 */
class ViewKeys extends TableView
{
	protected $keyType;

	public function init ()
	{
		parent::init();

		if (isset($this->keyType))
			$this->addAddParam ('keyType', $this->keyType);

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'valid', 'title' => 'Platné');
		$mq [] = array ('id' => 'archive', 'title' => 'Neplatné');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		if ($item['personName'])
			$listItem ['t1'] = $item['personName'];
		else
			$listItem ['t1'] = utils::es ('--- nepřiřazeno ---');

		$props [] = array ('icontxt' => '#', 'text' => $item['number']);
		$props [] = array ('icon' => 'tables/e10.persons.keys', 'text' => $item['key']);
		$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT ks.*, persons.fullName as personName FROM e10_persons_persons AS persons RIGHT JOIN e10_persons_keys as ks ON (ks.person = persons.ndx) WHERE 1';

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' ks.[key] LIKE %s', $fts.'%');
			array_push ($q, ' OR ks.[number] LIKE %s', $fts.'%');
			array_push ($q, ' OR persons.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		// -- keyType
		if (isset($this->keyType))
			array_push ($q, " AND ks.[keyType] = %s", $this->keyType);

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND ks.[docStateMain] <= 2");

		// -- platné
		if ($mainQuery == 'valid')
			array_push ($q, " AND ks.[docStateMain] = 2");

		// archive
		if ($mainQuery == 'archive')
			array_push ($q, " AND ks.[docStateMain] = 5");

		// koš
		if ($mainQuery == 'trash')
			array_push ($q, " AND ks.[docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [ndx]' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY ks.[docStateMain], [ndx]' . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows
} // class ViewKeys


/**
 * ViewDetailKey
 *
 */

class ViewDetailKey extends TableViewDetail
{
	public function createDetailContent()
	{
//		$this->addContent(array ('type' => 'text', 'subtype' => 'code', 'text' => utils::json_lint ($this->item['requestData'])));
	}
}


/**
 * class FormKey
 */

class FormKey extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('keyType');
			$this->addColumnInput ('person');
			$this->addColumnInput ('key');
			$this->addColumnInput ('number');
		$this->closeForm ();
	}
}

