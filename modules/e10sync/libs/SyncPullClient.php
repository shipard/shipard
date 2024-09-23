<?php

namespace e10sync\libs;
use \Shipard\Application\DataModel;


/**
 * class SyncPullClient
 */
class SyncPullClient extends \Shipard\Base\Utility
{
  var $syncSrc = 1;
  var $addLabels = [];

  protected function doImport ($table, $dataItem)
	{
		$this->db()->begin();

		$newRec = $dataItem['rec'];
		if (isset($newRec['ndx']))
			unset($newRec['ndx']);

		//$accessLevel = $table->checkAccessToDocument ($newRec);
		//if ($accessLevel !== 2)
		//	return $this->setStatus(ObjectsPut::psUnauthorized, 'Access denied');

		$operation = 'insert';
		$importPKColumnName = '';
		if (isset($dataItem['pkColumnName']))
			$importPKColumnName = $dataItem['pkColumnName'];
		elseif (isset($newRec['impNdx']))
			$importPKColumnName = 'impNdx';
		elseif (isset($newRec['syncNdx']))
			$importPKColumnName = 'syncNdx';
		elseif (isset($newRec['impId']))
			$importPKColumnName = 'impId';
		elseif (isset($newRec['id']))
			$importPKColumnName = 'id';
		elseif (isset($newRec['ndx']))
			$importPKColumnName = 'ndx';

		// -- check exist
		if ($importPKColumnName !== '')
		{
			if (is_string($newRec[$importPKColumnName]))
				$qe = 'SELECT ndx FROM ['.$table->sqlName().'] WHERE ['.$importPKColumnName.'] = %s';
			else
				$qe = 'SELECT ndx FROM ['.$table->sqlName().'] WHERE ['.$importPKColumnName.'] = %i';
			$re = $this->db()->query($qe, $newRec[$importPKColumnName])->fetch();
			if ($re && $re['ndx'])
			{
				$newRec['ndx'] = $re['ndx'];
				$operation = 'update';
			}
		}

		if ($operation === 'insert' && isset($dataItem['recInsert']))
		{
			foreach ($dataItem['recInsert'] as $key => $value)
				$newRec[$key] = $value;
		}

		$this->checkPrimaryKeys($table, $newRec);

		if ($operation === 'insert')
		{
			if (isset($dataItem['primitive']))
			{
				$this->db()->query ("INSERT INTO [{$table->sqlName()}]", $newRec);
			}
			else
			{
				$table->checkNewRec($newRec);
				$newItemNdx = $table->dbInsertRec($newRec);
				if (!$newItemNdx)
					return 0;//$this->setStatus(ObjectsPut::psInsertFailed, 'Insert failed');
				$newRec['ndx'] = $newItemNdx;
				//$this->result ['ndx'] = $newItemNdx;
				$newRec = $table->loadItem($newItemNdx);
			}
		}
		else
		{ // update
			$newItemNdx = $table->dbUpdateRec($newRec);
			$newRec = $table->loadItem($newItemNdx);
		}

		// lists
		if (isset($dataItem['lists']))
		{
			foreach ($dataItem['lists'] as $dipId => $dipContent)
			{
				if ($dipId === 'docLinks' || $dipId === 'doclinks')
				{
					$this->addDocLinks($table, $newRec, $table, $dipContent);
					continue;
				}
				if ($dipId === 'groups' && $table->tableId() === 'e10.persons.persons')
				{

					continue;
				}

				if ($dipId === 'attachments')
				{
					//$this->addAttachments($newRec, $this->table, $dipContent);
					continue;
				}



				$listDefinition = $table->listDefinition($dipId);
				if ($listDefinition === NULL)
				{
					$this->err("Invalid list '$dipId'");
					continue;
				}

				$listObject = $this->app->createObject($listDefinition ['class']);

				$listTable = NULL;
				if (isset ($listDefinition['table']))
					$listTable = $this->app->table($listDefinition['table']);

				$listData = [];
				foreach ($dipContent as $lr)
				{
					if (!is_array($lr))
					{
						return FALSE;//$this->setStatus(ObjectsPut::psListItemIsNotArray, "List item '$dipId' is not array: " . json_encode($lr));
					}
					$rd = $lr;
					$rd['ndx'] = 0;
					//$this->checkColumnValues($this->table, $newRec);
					$this->checkPrimaryKeys($listTable, $rd);
					$listData [] = $rd;
				}

				$listObject->setRecData($table, $dipId, $newRec);
				$listObject->saveData($listData);
			}
		}

		// -- "close" document
		$docStatesDef = $this->app->model()->tableProperty ($table, 'states');
		if ($docStatesDef)
		{
			$f = $table->getTableForm ('edit', $newItemNdx);

			if (!isset($f->recData[$docStatesDef['stateColumn']]))
				$f->recData[$docStatesDef['stateColumn']] = 4000;
			if (!isset($f->recData[$docStatesDef['mainStateColumn']]))
				$f->recData[$docStatesDef['mainStateColumn']] = 2;

			if ($f->checkAfterSave())
				$table->dbUpdateRec ($f->recData);

			$f->checkAfterSave();
			$table->checkDocumentState ($f->recData);
			$table->dbUpdateRec ($f->recData);

			$this->doImportDataRows ($dataItem, $f->recData);

			$table->checkAfterSave2 ($f->recData);

			$table->docsLog ($f->recData['ndx']);

      /*
      if (isset($f->recData['id']))
				$this->result ['id'] = $f->recData['id'];
			if (isset($f->recData['docNumber']))
				$this->result ['docNumber'] = $f->recData['docNumber'];

			$this->result ['recData'] = $f->recData;
      */
		}

    $this->importAssociated($dataItem);

		$this->db()->commit();

		return $f->recData['ndx'];//$this->status;
	}

	public function addDocLinks ($mainTable, $ownerRecData, $ownerTable, $docLinks)
	{
		$tableDocLinks = $this->app->table ('e10.base.doclinks');
		$deletedIds = [];
		$allLinksTotal = $this->app->cfgItem ('e10.base.doclinks', NULL);

		if (!$allLinksTotal || !isset($allLinksTotal[$mainTable->tableId()]))
			return;

		$allLinks = $allLinksTotal[$mainTable->tableId()];
		foreach ($docLinks as $docLinkId => $dcslnks)
		{
			if (!isset($allLinks[$docLinkId]))
				continue;
			foreach ($dcslnks as $dl)
			{
				$newDocLink = [];
				$newDocLink['linkId'] = $docLinkId;
				$newDocLink['srcRecId'] = $ownerRecData['ndx'];
				$newDocLink['srcTableId'] = $ownerTable->tableId();
				$newDocLink['dstTableId'] = $dl['dstTableId'];
				$newDocLink['dstRecId'] = $dl['dstRecId'];

				$this->checkPrimaryKeys($tableDocLinks, $newDocLink);

				$deleteId = $newDocLink['linkId'] . '-' . $newDocLink['srcRecId'] . '-' . $newDocLink['srcTableId'] . '-' . $newDocLink['dstTableId'];
				if (!in_array($deleteId, $deletedIds))
				{
					$this->db()->query('DELETE FROM [e10_base_doclinks]',
						' WHERE [linkId] = %s', $newDocLink['linkId'],
						' AND [srcRecId] = %i', $newDocLink['srcRecId'],
						' AND [srcTableId] = %s', $newDocLink['srcTableId'],
						' AND [dstTableId] = %s', $newDocLink['dstTableId']
					);
					$deletedIds[] = $deleteId;
				}

				$this->db()->query('INSERT INTO [e10_base_doclinks]', $newDocLink);
			}
		}
	}

	protected function doImportDataRows ($dataItem, $headRecData)
	{
		if (!isset($dataItem['dataRows']))
			return;

		foreach ($dataItem['dataRows'] as $dataId => $data)
		{
			$table = $this->app()->table ($data['table']);

			if (isset($data['deleteColumns']))
			{
				$qd = [];
				array_push($qd, 'DELETE FROM ['.$table->sqlName().'] WHERE 1 ');
				foreach ($data['deleteColumns'] as $queryDestColId => $queryHeadColId)
					array_push($qd, 'AND ['.$queryDestColId.'] = %s', $headRecData[$queryHeadColId]);
				$this->db()->query($qd);
			}

			foreach ($data['rows'] as $r)
			{
				$newRow = $r;
				if (isset($data['copyFromHead']))
				{
					foreach ($data['copyFromHead'] as $srcColId => $destColId)
						$newRow [$destColId] = $headRecData[$srcColId];
				}
				$this->checkPrimaryKeys($table, $newRow);
				$this->db()->query('INSERT INTO ['.$table->sqlName().']', $newRow);
			}
		}
	}

	function checkPrimaryKeys($table, &$recData)
	{
		if (!$table)
			return;
		foreach ($recData as $colId => $colValue)
		{
			$colDef = $table->column ($colId);
			if (!$colDef)
			{
				if ($colId[0] !== '_')
				{
					error_log("INVALID COLUMN: `{$colId}` in table `".$table->tableId()."`");
					//$this->result ['errors'][] = "INVALID COLUMN/objectsImport: column {$colId}";
				}
				continue;
			}
			if ($colDef['type'] !== DataModel::ctInt && $colDef['type'] !== DataModel::ctEnumInt && $colId !== 'ndx')
				continue;
			if (is_string($colValue) && $colValue !== '' && $colValue[0] === '@')
			{
				$dstTableId = '';
				$dstCol = 'id';
				$dstValue = substr($colValue, 1);
				$dstParts = explode(':', $dstValue);
				if (count($dstParts) >= 2)
				{
					$dstCol = $dstParts[0];
					$dstValue = substr($colValue, strlen($dstCol) + 2);
				}
				$dstColParts = explode(';', $dstCol);
				if (count($dstColParts) === 2)
				{
					$dstTableId = $dstColParts[0];
					$dstCol = $dstColParts[1];
				}

				if ($dstTableId === '' && !isset($colDef['reference']) && $colId !== 'ndx')
				{
					error_log("BAD REFERENCE: column {$colId}\n");
					continue;
				}
				if ($dstTableId !== '')
					$tableRef = $this->app->table($dstTableId);
				elseif ($colId === 'ndx')
					$tableRef = $table;
				else
					$tableRef = $this->app->table($colDef['reference']);

				$q = [];
				array_push($q, 'SELECT ndx FROM ['.$tableRef->sqlName ().']');
				array_push($q, ' WHERE ['.$dstCol.'] = %s', $dstValue);
				if ($dstCol === 'syncNdx' /*&& isset($this->data['syncSrc'])*/)
					array_push($q, ' AND [syncSrc] = %i', $this->syncSrc);

				$refRow = $this->app->db()->query ($q)->fetch();
				if (isset($refRow['ndx']))
					$recData[$colId] = $refRow['ndx'];
				else
				{
					$recData[$colId] = 0;
					error_log("ERROR: primary key for '".$table->tableId()."::{$colId}' not found: '".json_encode($colValue)."' from '".$tableRef->tableId()."::{$dstCol}'\n");
				}
			}
		}
	}

  protected function importAssociated($dataItem)
  {
    if (!isset($dataItem['associated']))
      return;

    foreach ($dataItem['associated'] as $assocId => $assoc)
    {
      $table = $this->app()->table($assoc['table']);
      if (!$table)
      {
        continue;
      }
      foreach ($assoc['recs'] as $assocDataItem)
      {
        $this->doImport($table, $assocDataItem);
      }
    }
  }

  public function setAddLabels($labelsNdxs)
  {
    $ndxs = [];
    $ndxsParts = preg_split("/[\s,]+/", $labelsNdxs);
    foreach ($ndxsParts as $ndxStr)
    {
      $ndx = intval($ndxStr);
      if (!$ndx)
        continue;
      $ndxs[] = $ndx;
    }

    foreach ($ndxs as $n)
    {
      $labelRecData = $this->db()->query('SELECT * FROM [e10_base_clsfitems] WHERE ndx = %i', $n)->fetch();
      if (!$labelRecData)
        continue;

      $this->addLabels[] = ['ndx' => $n, 'group' => $labelRecData['group']];
    }
  }

  protected function addLabels($table, $recNdx)
  {
    foreach ($this->addLabels as $label)
    {
      $this->addLabel($table, $recNdx, $label['ndx'], $label['group']);
    }
  }

  protected function addLabel($dstTable, $dstRecNdx, $labelNdx, $labelGroup)
  {
    $labelExist = $this->db()->query('SELECT * FROM e10_base_clsf WHERE tableid = %s', $dstTable->tableId(),
                                      ' AND clsfItem = %i', $labelNdx, ' AND [group] = %s', $labelGroup,
                                      ' AND recid = %i', $dstRecNdx)->fetch();
    if (!$labelExist)
    {
      $label = [
        'clsfItem' => $labelNdx, 'group' => $labelGroup,
        'tableid' => $dstTable->tableId(), 'recid' => $dstRecNdx,
      ];
      $this->db()->query('INSERT INTO [e10_base_clsf] ', $label);
    }
  }
}

