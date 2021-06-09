<?php

namespace E10\Base;

use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \e10\json;


/**
 * Class TableDocsLog
 * @package E10\Base
 */
class TableDocsLog extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.docslog", "e10_base_docslog", "Log dokumentů");
	}

	public function eventResultClass ($eventResult)
	{
		static $classes = [0 => '', 1 => 'e10-row-plus', 2 => 'e10-warning1', 3 => 'e10-warning3'];
		return $classes[$eventResult];

	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$itemTop = [
			['icon' => 'system/iconSitemap', 'text' => $recData['ipaddress']],
			['icon' => 'deviceTypes/workStation', 'text' => $recData['deviceId']]
		];

		$hdr ['info'][] = array ('class' => 'info', 'value' => $itemTop);

		if ($recData['eventType'] == 0)
		{
			$hdr ['info'][] = array ('class' => 'title', 'value' => $recData ['eventTitle']);
		}

		return $hdr;
	}
} // class TableDocsLog


/**
 * Class ViewDocsLogAll
 * @package E10\Base
 */
class ViewDocsLogAll extends TableView
{
	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT log.*, persons.fullName as personName FROM [e10_base_docslog] as log ' .
						'LEFT JOIN e10_persons_persons as persons ON log.user = persons.ndx WHERE 1';

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, "AND (");
			array_push ($q, "persons.[fullName] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR [eventTitle] LIKE %s", '%'.$fts.'%');
			array_push ($q, ") ");
		}

		array_push ($q, ' ORDER BY [ndx] DESC' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows


	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		if ($item['eventType'] == 0)
		{ // document
			$table = $this->table->app()->table ($item['tableid']);
			if ($table)
			{
				$docStates = $table->documentStates($item);

				$docStateName = $table->getDocumentStateInfo($docStates, $item, 'name');
				$docStateLogName = $table->getDocumentStateInfo($docStates, $item, 'logName');
				$docStateIcon = $table->getDocumentStateInfo($docStates, $item, 'styleIcon');
				$docStateClass = $table->getDocumentStateInfo($docStates, $item, 'styleClass');

				$listItem ['icon'] = $docStateIcon;
				$listItem ['class'] = $docStateClass;

				$props1 [] = array('icon' => 'icon-table', 'text' => $table->tableName());
				if ($item['docTypeName'])
					$props1 [] = array('text' => $item['docTypeName']);
				$props1 [] = array('i' => 'file', 'text' => $item['docID'], 'docAction' => 'edit', 'table' => $item['tableid'], 'pk' => $item ['recid']);
				$listItem ['t1'] = $props1;

				if ($docStateLogName !== FALSE)
					$props2 [] = array('icon' => 'icon-chevron-right', 'text' => $docStateLogName);
				else
					$props2 [] = array('icon' => 'icon-chevron-right', 'text' => $docStateName);
				$props2 [] = array('icon' => 'system/iconUser', 'text' => $item['personName']);
			}
			else
			{
				$listItem ['t1'] = 'chyba';
			}
		}
		else
		if ($item['eventType'] == 2)
		{ // user access
			$listItem ['icon'] = 'system/iconUser';
			$listItem ['t1'] = $item['personName'];
			$props2 [] = array ('icon' => 'system/iconSitemap', 'text' => $item['ipaddress']);
		}
		else
		if ($item['eventType'] === 3)
		{ // system check
			$listItem ['icon'] = 'system/iconCogs';
			$props2 [] = ['icon' => 'system/iconSitemap', 'text' => $item['ipaddress']];
			if (!isset($listItem ['class']))
				$listItem ['class'] = '';
			$listItem ['class'] .= $this->table->eventResultClass($item['eventResult']);
			$listItem ['t1'] = $item ['eventTitle'];
			$listItem ['t3'] = $item ['eventSubtitle'];
		}
		$props2 [] = array ('icon' => 'icon-clock-o', 'text' => utils::datef ($item['created'], '%D, %T'));
		$listItem ['t2'] = $props2;

		if ($item ['eventTitle'] != '' && !isset($listItem ['t3']))
			$listItem ['t3'] = $item ['eventTitle'];

		return $listItem;
	}

	public function createDetails ()
	{
		return array ();
	}

	public function createToolbar ()
	{
		return array ();
	} // createToolbar
} // class ViewDocsLogAll


/**
 * Class ViewDocsLogDoc
 * @package E10\Base
 */
class ViewDocsLogDoc extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		//$this->enableDetailSearch = TRUE;

		if ($this->queryParam ('recid'))
			$this->addAddParam ('recid', $this->queryParam ('recid'));
		if ($this->queryParam ('tableid'))
			$this->addAddParam ('tableid', $this->queryParam ('tableid'));
		parent::init();
	}

	public function selectRows ()
	{
		$q [] = 'SELECT log.*, persons.fullName as personName FROM [e10_base_docslog] as log ' .
						'LEFT JOIN e10_persons_persons as persons ON log.user = persons.ndx WHERE ';

		array_push ($q, '[tableid] = %s', $this->queryParam ('tableid'), ' AND [recid] = %i', $this->queryParam ('recid'));

		array_push ($q, ' ORDER BY [ndx] DESC' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows


	public function renderRow ($item)
	{
		$table = $this->table->app()->table ($item['tableid']);

		$listItem ['pk'] = $item ['ndx'];

		if ($item['eventType'] === 3)
		{ // system check
			$listItem ['icon'] = 'system/iconCogs';
			$listItem ['class'] = $this->table->eventResultClass($item['eventResult']);
			$listItem ['t1'] = $item ['eventTitle'];
			$listItem ['t3'] = $item ['eventSubtitle'];
		}
		else
		{
			$docStates = $table->documentStates ($item);

			$docStateName = $table->getDocumentStateInfo ($docStates, $item, 'name');
			$docStateIcon = $table->getDocumentStateInfo ($docStates, $item, 'styleIcon');
			$docStateClass = $table->getDocumentStateInfo ($docStates, $item, 'styleClass');

			$listItem ['t1'] = $item['personName'];
			$listItem ['i1'] = $docStateName;
			$listItem ['icon'] = $docStateIcon;
			$listItem ['class'] = $docStateClass;
		}

		$listItem ['i2'] = utils::datef ($item['created'], '%d, %T');

		$props2 [] = array ('i' => 'road', 'text' => $item ['ipaddress']);
		$props2 [] = array ('icon' => 'x-pc', 'text' => $item ['deviceId']);
		$listItem ['t2'] = $props2;

		if ($item['eventType'] == 0)
		{
			$diff = $this->rowDiff($item);
			if ($diff)
			{
				if (count($diff->diffContent))
				{
					$cr = new \e10\ContentRenderer($this->app());
					$cr->content = $diff->diffContent;
					$listItem['t3'] = ['code' => $cr->createCode()];
				}
			}
		}

		return $listItem;
	}

	function rowDiff($item)
	{
		$q = [];
		array_push($q, 'SELECT * FROM [e10_base_docslog]');
		array_push($q, ' WHERE [tableid] = %s', $item['tableid']);
		array_push($q, ' AND [recid] = %i', $this->queryParam ('recid'));
		array_push($q, ' AND [eventType] = %i', 0);
		array_push($q, ' AND [ndx] < %i', $item['ndx']);
		array_push($q, ' ORDER BY ndx DESC');
		array_push($q, ' LIMIT 1');

		$oldDataRec = $this->db()->query($q)->fetch();
		if (!$oldDataRec)
			return NULL;

		$oldData = json_decode($oldDataRec['eventData'], TRUE);
		$newData = json_decode($item['eventData'], TRUE);

		$diff = new \lib\core\logs\DocumentDiff($this->app());
		$diff->setData($item['tableid'], $oldData, $newData);
		$diff->run();
		return $diff;
	}

	public function createToolbar ()
	{
		return array ();
	}
}


/**
 * ViewDetailDocsLog
 *
 */

class ViewDetailDocsLog extends TableViewDetail
{
	public function createToolbar ()
	{
		$toolbar = array ();
		return $toolbar;
	}

	public function createDetailContent ()
	{
		$text = json::lint(json_decode($this->item['eventData']));
		$this->addContent(['type' => 'text', 'subtype' => 'code', 'text' => $text]);
	}
}


/*
 * FormDocsLog
 *
 */

class FormDocsLog extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ("eventData");
		$this->closeForm ();
	}

} // class FormDocsLog

