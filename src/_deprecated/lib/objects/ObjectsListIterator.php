<?php

namespace lib\objects;
use E10\Utility, E10\utils;



/**
 * Class ObjectsListIterator
 * @package lib\objects
 */
class ObjectsListIterator extends Utility
{
	/** @var \E10\DbTable */
	var $table = NULL;

	var $tableLists;
	var $listsTables;

	var $rows;

	var $params;
	var $allColumns = FALSE;
	var $limitCountRows = 0;
	var $limitFirstRow = 0;
	var $syncSrc = 0;

	public function nextObject ()
	{
		$row = $this->rows->fetch ();
		if (!$row)
			return FALSE;

		$object = [];
		$object ['rec'] = $this->rec ($this->table, $row->toArray ());
		if ($this->syncSrc)
		{
			$object ['rec']['syncSrc'] = $this->syncSrc;
			$object ['rec']['syncNdx'] = $object ['rec']['ndx'];
		}
		$this->nextObjectLists ($object);
		$this->nextObjectAttachments ($object);

		return $object;
	}

	public function nextObjectLists (&$object)
	{
		if (!$this->tableLists)
			return;

		forEach ($this->tableLists as $listId => $list)
		{
			$listObject = $this->app->createObject ($list ['class']);
			$listObject->setRecData ($this->table, $listId, $object['rec']);
			$listObject->loadData ();
			if (!count($listObject->data))
				continue;

			if ($listId === 'doclinks')
			{
				foreach ($listObject->data as $linkId => $linkContent)
				{
					foreach ($linkContent as $r)
					{
						$rec =  $this->rec($this->listsTables[$listId], $r);
						if ($this->syncSrc)
						{
							$rec['dstRecId'] = '@'.$rec['dstTableId'].';syncNdx:' . $rec['dstRecId'];
						}
						$object['lists'][$listId][$linkId][] = $rec;
					}
				}
			}
			else
			if ($listId === 'clsf')
			{
				//error_log ("--------------".$listId);
				//error_log(json_encode($listObject->data));
			}
			else
			if ($listId === 'groups')
			{
				$object['lists'][$listId] = $listObject->data;
			}
			else
			{
				foreach ($listObject->data as $r)
				{
					$object['lists'][$listId][] = $this->rec($this->listsTables[$listId], $r, $listId);
				}
			}
		}
	}

	public function nextObjectAttachments (&$object)
	{
		if (!isset ($object['rec']['ndx']))
			return;
		$files = \E10\base\getAttachments ($this->app, $this->table->tableId(), $object['rec']['ndx'], TRUE);
		if (!count($files))
			return;
		foreach ($files as &$f)
		{
			$f['url'] = \E10\Base\getAttachmentUrl ($this->app, $f, 0, 0, TRUE);
			unset ($f['tableid'], $f['recid'], $f['attplace'], $f['created'], $f['deleted']);

			if (!$this->allColumns)
			{
				if ($f['perex'] === '') unset ($f['perex']);
				if ($f['atttype'] === '') unset ($f['atttype']);

				if ($f['symlinkTo'] === 0) unset ($f['symlinkTo']);
				if ($f['order'] === 0) unset ($f['order']);
				if ($f['defaultImage'] === 0) unset ($f['defaultImage']);
			}
		}

		$object['attachments'] = $files;
	}

	public function rec ($table, $recData, $listId = NULL)
	{
		$rec = [];

		$col = NULL;
		forEach ($recData as $key => $value)
		{
			if ($table)
			{
				$col = $table->column($key);
				if (!$col)
					continue;
			}

			if (!$listId && isset($this->params['exclude']['columns']))
			{
				if (is_array($this->params['exclude']['columns']) && in_array($key, $this->params['exclude']['columns']))
					continue;
				if (is_string($this->params['exclude']['columns']) && $key === $this->params['exclude']['columns'])
					continue;
			}

			if ($recData[$key] instanceof \DibiDateTime)
			{
				$rec[$key] = $recData[$key]->format('Y-m-d');
			}
			else
			if ($this->allColumns)
			{
				$rec[$key] = $recData[$key];
			}
			else
			{
				if (is_string($recData[$key]) && $recData[$key] === '')
					;
				elseif (is_int($recData[$key]) && $recData[$key] === 0)
					;
				else
					$rec[$key] = $recData[$key];
			}

			if ($this->syncSrc && $col && isset($col['reference']) && isset($rec[$key]))
			{
				if ($rec[$key] != 0)
				{
					if ($listId && isset($this->params['pkid'][$listId]) && is_array($this->params['pkid'][$listId]) && in_array($key, $this->params['pkid'][$listId]))
					{
						$rec[$key] = '@id:' . $this->referenceId ($rec[$key], $col['reference']);
					}
					elseif ($listId && isset($this->params['pkid'][$listId]) && is_string($this->params['pkid'][$listId]) && $key === $this->params['pkid'][$listId])
					{
						$rec[$key] = '@id:' . $this->referenceId ($rec[$key], $col['reference']);
					}
					else
						$rec[$key] = '@syncNdx:' . $rec[$key];
				}
			}
		}

		return $rec;
	}

	public function setParams ($params)
	{
		$this->params = $params;

		if (isset ($params['allColumns']))
		{
			if (in_array($params['allColumns'], ['0', '1']))
				$this->allColumns = intval($params['allColumns']);
			else
				$this->addMessage("Invalid 'allColumns' param value");
		}

		if (isset ($params['limitCountRows']))
		{
			if (utils::is_uint ($params['limitCountRows']))
				$this->limitCountRows = intval ($params['limitCountRows']);
			else
				$this->addMessage("Invalid 'limitCountRows' param value");
		}

		if (isset ($params['limitFirstRow']))
		{
			if (utils::is_uint ($params['limitFirstRow']))
				$this->limitFirstRow = intval ($params['limitFirstRow']);
			else
				$this->addMessage("Invalid 'limitFirstRow' param value");
		}

		if (isset ($params['syncSrc']))
		{
			if (utils::is_uint ($params['syncSrc']))
				$this->syncSrc = intval ($params['syncSrc']);
			else
				$this->addMessage("Invalid 'syncSrc' param value");
		}
	}

	public function setTable ($table)
	{
		$this->table = $table;
		$this->tableLists = $this->table->listDefinition (NULL);

		if ($this->tableLists)
		{
			forEach ($this->tableLists as $listId => $list)
			{
				if (isset ($list['table']))
					$this->listsTables[$listId] = $this->app->table ($list['table']);
				else
					$this->listsTables[$listId] = NULL;
			}
		}
	}

	public function select ()
	{
		$q = $this->query ();
		$this->rows = $this->db()->query($q);
	}

	protected function query ()
	{
		$q [] = 'SELECT * FROM '. $this->table->sqlName ();

		$this->queryWhere($q);
		$this->queryOrder($q);
		$this->queryLimit($q);

		return $q;
	}

	protected function queryLimit (&$q)
	{
		if ($this->limitCountRows)
			array_push($q, ' LIMIT %i, %i', $this->limitFirstRow, $this->limitCountRows);
	}

	protected function queryWhere (&$q)
	{
		array_push($q, ' WHERE 1');

		// -- documents states: default is all except deleted
		$docStates = $this->app->model()->tableProperty ($this->table, 'states');
		if ($docStates)
		{
			if (isset ($docStates['mainStateColumn']) && !isset($this->params['query'][$docStates['mainStateColumn']]))
				array_push($q, ' AND ['.$docStates['mainStateColumn'].'] != 4');
		}

		// -- queries from params
		if (isset ($this->params['query']))
		{
			foreach ($this->params['query'] as $colId => $colValue)
			{
				$col = $this->table->column($colId);
				if (!$col)
				{
					$this->addMessage('Invalid query column id: --query-'.$colId);
					continue;
				}

				if (is_array($colValue))
					array_push($q, ' AND ['.$colId.'] IN %in', $colValue);
				else
					array_push($q, ' AND ['.$colId.'] = ?', $colValue);
			}
		}
	}

	protected function queryOrder (&$q)
	{
		if (isset ($this->params['order']))
		{
			$oc = [];
			foreach ($this->params['order'] as $colId => $colValue)
			{
				$col = $this->table->column($colId);
				if (!$col)
				{
					$this->addMessage('Invalid order column id: --order-'.$colId);
					continue;
				}
				if ($colValue !== 'a' && $colValue !== 'd')
				{
					$this->addMessage('Invalid order column value: --order-'.$colId);
					continue;
				}

				if ($colValue == 'a')
					$oc [] = '['.$colId.']';
				else
					$oc [] = '['.$colId.'] DESC';
			}
			array_push($q, ' ORDER BY '.implode(', ', $oc));
		}
		else
			array_push($q, ' ORDER BY ndx');
	}

	function referenceId ($srcRecNdx, $refTableId)
	{
		$refTable = $this->app()->table($refTableId);
		if (!$refTable)
		{
			return '0';
		}

		$rec = $this->db()->query('SELECT [id] FROM ['.$refTable->sqlName().'] WHERE [ndx] = %i', $srcRecNdx)->fetch();
		if ($rec)
			return $rec['id'];

		return '0';
	}
}
