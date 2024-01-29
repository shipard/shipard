<?php

namespace services\persons;

use \Shipard\Table\DbTable;
use \Shipard\Viewer\TableView;
use \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableRegsChanges
 */
class TableRegsChanges extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.persons.regsChanges', 'services_persons_regsChanges', 'Změny v registrech');
	}
}


/**
 * class ViewRegsChanges
 */
class ViewRegsChanges extends TableView
{
	var $registers;
  var $changesStates;

	public function init()
	{
		parent::init();
		$this->registers = $this->app()->cfgItem('services.personsRegisters', []);
    $this->changesStates = $this->table->columnInfoEnum ('changeState', 'cfgText');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = ['suffix' => Utils::Datef($item['changeDay']), 'class' => '', 'text' => '#'.$item['changeSetId']];

		$listItem ['i1'] = ['text' => '#'.$item ['ndx'], 'class' => 'id'];
    $listItem ['t2'][] = ['text' => 'Počet změn: '.Utils::nf($item ['cntChanges'], 0), 'class' => 'label label-default'];
    $listItem ['t2'][] = ['text' => 'Staženo: '.Utils::datef($item ['tsDownload'], '%d%t'), 'class' => ''];

		$listItem ['i2'] = $this->changesStates[$item ['changeState']];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [changes].* ');
		array_push ($q, ' FROM [services_persons_regsChanges] AS [changes]');
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
 * class ViewDetailRegChanges
 */
class ViewDetailRegChanges extends TableViewDetail
{
	public function createDetailContent ()
	{
    $this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'subtype' => 'code', 'text' => $this->item['srcData']]);
  }
}
