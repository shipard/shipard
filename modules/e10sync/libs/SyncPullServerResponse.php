<?php

namespace e10sync\libs;


/**
 * class SyncPullServerResponse
 */
class SyncPullServerResponse extends \Shipard\Base\ApiObject2
{
  var $syncSrc = 1;

	public function loadItem ($ndx, $table)
	{
		$row = $table->loadItem ($ndx);
		if (!$row)
			return NULL;

		$object = [];
		$object ['rec'] = $this->rec ($table, $row);
		if ($this->syncSrc)
		{
			$object ['rec']['syncSrc'] = $this->syncSrc;
			$object ['rec']['syncNdx'] = $object ['rec']['ndx'];
		}
		$this->loadItemLists ($table, $object);
    //$this->nextObjectAttachments ($object);

		return $object;
	}

	public function loadItemLists ($mainTable, &$object)
	{
    $tableLists = $mainTable->listDefinition (NULL);
		if (!$tableLists)
			return;

    $listsTables = [];
    forEach ($tableLists as $listId => $list)
    {
      if (isset ($list['table']))
        $listsTables[$listId] = $this->app->table ($list['table']);
      else
        $listsTables[$listId] = NULL;
    }

		forEach ($tableLists as $listId => $list)
		{
			$listObject = $this->app->createObject ($list ['class']);
			$listObject->setRecData ($mainTable, $listId, $object['rec']);
			$listObject->loadData ();
			if (!count($listObject->data))
				continue;

			if ($listId === 'doclinks')
			{
				foreach ($listObject->data as $linkId => $linkContent)
				{
					foreach ($linkContent as $r)
					{
						$rec =  $this->rec($listsTables[$listId], $r);
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
					$object['lists'][$listId][] = $this->rec($listsTables[$listId], $r, $listId);
				}
			}
		}
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

			if (!$listId && isset($this->requestParams['exclude']['columns']))
			{
				if (is_array($this->requestParams['exclude']['columns']) && in_array($key, $this->requestParams['exclude']['columns']))
					continue;
				if (is_string($this->requestParams['exclude']['columns']) && $key === $this->requestParams['exclude']['columns'])
					continue;
			}

			if ($recData[$key] instanceof \DateTimeInterface)
			{
				$rec[$key] = $recData[$key]->format('Y-m-d');
			}
      /*
			else
			if ($this->allColumns)
			{
				$rec[$key] = $recData[$key];
			}*/
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
        if ($col['reference'] === 'e10.world.countries')
					continue;
				if ($rec[$key] != 0)
				{
					if ($listId && isset($this->requestParams['pkid'][$listId]) && is_array($this->requestParams['pkid'][$listId]) && in_array($key, $this->requestParams['pkid'][$listId]))
					{
						$rec[$key] = '@id:' . $this->referenceId ($rec[$key], $col['reference']);
					}
					elseif ($listId && isset($this->requestParams['pkid'][$listId]) && is_string($this->requestParams['pkid'][$listId]) && $key === $this->requestParams['pkid'][$listId])
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
