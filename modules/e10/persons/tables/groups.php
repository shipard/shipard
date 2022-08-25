<?php

namespace e10\persons;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;


/**
 * Class TableGroups
 */
class TableGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.groups', 'e10_persons_groups', 'Skupiny osob');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$groups = [];
		$systemMap = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10_persons_groups] WHERE docState != 9800 ORDER BY [name]');
		forEach ($rows as $r)
		{
			$groups [$r['ndx']] = ['id' => $r['ndx'], 'name' => $r ['name'], 'sg' => $r ['systemGroup']];
			if ($r['systemGroup'] != '-')
				$systemMap [$r['systemGroup']] = $r ['ndx'];
		}

		// save groups to file
		$cfg ['e10']['persons']['groups'] = $groups;
		$cfg ['e10']['persons']['groupsToSG'] = $systemMap;
		file_put_contents(__APP_DIR__ . '/config/_e10.persons.groups.json', json_encode ($cfg));

		// -- properties
		unset ($cfg);
		$tablePropDefs = new \E10\Base\TablePropdefs($this->app());
		$cfg ['e10']['persons']['groupsProperties'] = $tablePropDefs->propertiesConfig($this->tableId());
		file_put_contents(__APP_DIR__ . '/config/_e10.persons.groupsProperties.json', Utils::json_lint(json_encode ($cfg)));
	}
}


/**
 * Class ViewGroups
 */
class ViewGroups extends TableView
{
	var $linkedPersons;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		parent::init();
	}

	public function selectRows ()
	{
		$q [] = 'SELECT * FROM [e10_persons_groups]';
		array_push($q, ' WHERE 1');

		// -- fulltext
		$fts = $this->fullTextSearch ();
		if ($fts !== '')
			array_push ($q, " AND ([name] LIKE %s)", '%'.$fts.'%');

		$this->queryMain ($q, '', ['[name]', 'ndx']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['name'];

		$sg = $this->table->columnInfoEnum ('systemGroup', 'cfgText');
		if ($item['systemGroup'] !== '-' && isset($sg[$item['systemGroup']]))
			$listItem ['t2'] = ['text' => $sg[$item['systemGroup']], 'icon' => 'icon-user-secret'];

		return $listItem;
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks);
	}

	function decorateRow (&$item)
	{
		if (isset ($this->linkedPersons [$item ['pk']]['e10-persons-groups-balances']))
		{
			$this->linkedPersons [$item ['pk']]['e10-persons-groups-balances'][0]['icon'] = 'system/iconStar';
			$item ['i2'] = $this->linkedPersons [$item ['pk']]['e10-persons-groups-balances'];
		}
	}
} // class ViewGroups


/**
 * class ViewDetailGroups
 */
class ViewDetailGroups extends TableViewDetail
{
}


/**
 * class FormGroups
 */
class FormGroups extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('name');
			$this->addColumnInput ('systemGroup');
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}

}
