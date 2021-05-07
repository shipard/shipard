<?php


namespace e10\base\libs;


use E10\TableView;
use E10\utils;


/**
 * Class ViewDocsLogDocHistory
 * @package e10\base\libs
 */
class ViewDocsLogDocHistory extends TableView
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
			$listItem ['icon'] = 'icon-cogs';
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
			$listItem ['icon'] = 'icon-' . $docStateIcon;
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
