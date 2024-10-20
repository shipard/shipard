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
		$this->registers = $this->app()->cfgItem('services.personsRegisters', []);
    $this->changesStates = $this->table->columnInfoEnum ('changeState', 'cfgText');

		$mq [] = ['id' => 'active', 'title' => 'Zpracovat'];
		$mq [] = ['id' => 'done', 'title' => 'Hotovo'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = ['suffix' => Utils::Datef($item['changeDay']), 'class' => '', 'text' => $item['changeSetId'], 'icon' => 'user/fileText'];

		$listItem ['i1'] = ['text' => '#'.$item ['ndx'], 'class' => 'id'];
    $listItem ['t2'][] = ['text' => 'Počet změn: '.Utils::nf($item ['cntChanges'], 0), 'class' => 'label label-default'];
		$listItem ['t2'][] = ['text' => 'Hotovo: '.Utils::nf($item ['cntDone'], 0), 'class' => 'label label-default'];

    $listItem ['t2'][] = ['text' => Utils::datef($item ['tsDownload'], '%d'), 'suffix' => Utils::datef($item ['tsDownload'], '%T'), 'class' => 'pull-right', 'icon' => 'system/actionSave'];


		$listItem ['i2'] = ['text' => $this->changesStates[$item ['changeState']]];

		if (!Utils::dateIsBlank($item['tsDone']) && $item ['changeState'] == 3)
		{
			$listItem ['i2']['text'] = Utils::datef($item ['tsDone'], '%d');
			$listItem ['i2']['suffix'] = Utils::datef($item ['tsDone'], '%T');
			$listItem ['i2']['icon'] = 'system/iconCheck';
		}

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [changes].*, ');
		array_push ($q, ' (SELECT COUNT(*) FROM services_persons_regsChangesItems WHERE changes.ndx = services_persons_regsChangesItems.regsChangeSet AND [done] = 1) AS cntDone');
		array_push ($q, ' FROM [services_persons_regsChanges] AS [changes]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND EXISTS (');
			array_push ($q, 'SELECT ndx FROM services_persons_regsChangesItems',
											' WHERE changes.ndx = services_persons_regsChangesItems.regsChangeSet ',
											' AND [oid] LIKE %s' , $fts.'%');
			array_push ($q, ')');
    }

		if ($mainQuery === 'active')
			array_push ($q, ' AND [changes].[changeState] != %i', 3);
		elseif ($mainQuery === 'done')
			array_push ($q, ' AND [changes].[changeState] = %i', 3);

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
