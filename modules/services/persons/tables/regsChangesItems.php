<?php

namespace services\persons;

use \Shipard\Table\DbTable;
use \Shipard\Viewer\TableView;
use \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableRegsChangesItems
 */
class TableRegsChangesItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.persons.regsChangesItems', 'services_persons_regsChangesItems', 'Položky změny v registrech');
	}
}


/**
 * class ViewRegsChanges
 */
class ViewRegsChangesItems extends TableView
{
	var $registers;
  var $changeTypes;

	public function init()
	{
		parent::init();
		$this->registers = $this->app()->cfgItem('services.personsRegisters', []);
    $this->changeTypes = $this->table->columnInfoEnum ('changeType', 'cfgText');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		//$listItem ['t1'] = ['suffix' => Utils::Datef($item['changeDay']), 'class' => '', 'text' => '#'.$item['changeSetId']];

    $listItem ['t1'] = ['text' => $item['oid'], 'suffix' => $this->changeTypes[$item ['changeType']]];

		$listItem ['i1'] = ['text' => '#'.$item ['ndx'], 'class' => 'id'];
    if ($item['changeDay'])
      $listItem ['i2'] = Utils::datef($item['changeDay']);

    if ($item['personName'])
      $listItem ['t2'] = $item['personName'];
    else
      $listItem ['t2'] = '';

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [items].*, [persons].[fullName] AS [personName], [changeSets].[changeDay]');
		array_push ($q, ' FROM [services_persons_regsChangesItems] AS [items]');
    array_push ($q, ' LEFT JOIN [services_persons_persons] AS [persons] ON [items].[person] = [persons].[ndx]');
    array_push ($q, ' LEFT JOIN [services_persons_regsChanges] AS [changeSets] ON [items].[regsChangeSet] = [changeSets].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
    }

    array_push ($q, ' ORDER BY ndx DESC');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailRegChangeItem
 */
class ViewDetailRegChangeItem extends TableViewDetail
{
	public function createDetailContent ()
	{
    //$this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code', 'text' => $this->item['srcData']]);
  }
}
