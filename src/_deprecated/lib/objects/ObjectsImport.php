<?php

namespace lib\objects;
use E10\Service, E10\Response, E10\DataModel;


/**
 * Class ObjectsImport
 * @package lib\objects
 */
class ObjectsImport extends Service
{
	const psOK = 0, psEmptyData = 1, psParseError = 2, psUnknownTable = 3, psInvalidTableId = 4,
			psUnknownColumn = 5, psRecNotFound = 6, psInsertFailed = 7, psUnauthorized = 8, psListItemIsNotArray = 9;

	protected $status = ObjectsPut::psOK;
	protected $operation;
	protected $dataString = '';
	protected $data = NULL;

	/** @var \e10\DbTable */
	protected $table = NULL;

	protected $importPKColumnName = '';

	var $result = [];

	protected function setStatus ($status, $msg = '')
	{
		$this->status = $status;
		$this->result ['errorCode'] = $status;
		$this->result ['errorMsg'] = $msg;

		return $status;
	}

	protected function parseData ()
	{
		$tableId = $this->app->requestPath(3);
		if (!isset($this->data['table']) == '')
			return $this->setStatus(ObjectsPut::psUnknownTable, 'Empty table id');

		$this->table = $this->app->table($tableId);
		if ($this->table === NULL)
			return $this->setStatus(ObjectsPut::psInvalidTableId, "Table '$tableId' not found");

		$this->dataString = $this->app->postData();
		if ($this->dataString == '')
			return $this->setStatus(ObjectsPut::psEmptyData, 'Empty POST data');

		$this->data = json_decode($this->dataString, TRUE);
		if ($this->data === NULL)
			return $this->setStatus(ObjectsPut::psParseError, 'Parse error: '.json_last_error_msg());

		if (!isset($this->data['rec']))
			return $this->setStatus(ObjectsPut::psRecNotFound, "Element 'rec' not found");
	}

	protected function doImport ()
	{
		if ($this->status !== ObjectsPut::psOK)
			return $this->status;

		$this->db()->begin();

		$newRec = $this->data['rec'];
		if (isset($newRec['ndx']))
			unset($newRec['ndx']);

		$accessLevel = $this->table->checkAccessToDocument ($newRec);
		if ($accessLevel !== 2)
			return $this->setStatus(ObjectsPut::psUnauthorized, 'Access denied');

		$this->operation = 'insert';
		$this->importPKColumnName = '';
		if (isset($newRec['impNdx']))
			$this->importPKColumnName = 'impNdx';
		elseif (isset($newRec['syncNdx']))
			$this->importPKColumnName = 'syncNdx';
		elseif (isset($newRec['impId']))
			$this->importPKColumnName = 'impId';
		elseif (isset($newRec['id']))
			$this->importPKColumnName = 'id';
		elseif (isset($newRec['ndx']))
			$this->importPKColumnName = 'ndx';

		// -- check exist
		if ($this->importPKColumnName !== '')
		{
			if (is_string($newRec[$this->importPKColumnName]))
				$qe = 'SELECT ndx FROM ['.$this->table->sqlName().'] WHERE ['.$this->importPKColumnName.'] = %s';
			else
				$qe = 'SELECT ndx FROM ['.$this->table->sqlName().'] WHERE ['.$this->importPKColumnName.'] = %i';
			$re = $this->db()->query($qe, $newRec[$this->importPKColumnName])->fetch();
			if ($re && $re['ndx'])
			{
				$newRec['ndx'] = $re['ndx'];
				$this->operation = 'update';
			}
		}

		if ($this->operation === 'insert' && isset($this->data['recInsert']))
		{
			foreach ($this->data['recInsert'] as $key => $value)
				$newRec[$key] = $value;
		}

		$this->checkPrimaryKeys($this->table, $newRec);

		if ($this->operation === 'insert')
		{
			if (isset($this->data['primitive']))
			{
				$this->db()->query ("INSERT INTO [{$this->table->sqlName()}]", $newRec);
			}
			else
			{
				$this->table->checkNewRec($newRec);
				$newItemNdx = $this->table->dbInsertRec($newRec);
				if (!$newItemNdx)
					return $this->setStatus(ObjectsPut::psInsertFailed, 'Insert failed');
				$newRec['ndx'] = $newItemNdx;
				$this->result ['ndx'] = $newItemNdx;
				$newRec = $this->table->loadItem($newItemNdx);
			}
		}
		else
		{ // update
			$newItemNdx = $this->table->dbUpdateRec($newRec);
			$newRec = $this->table->loadItem($newItemNdx);
		}

		// lists
		if (isset($this->data['lists']))
		{
			foreach ($this->data['lists'] as $dipId => $dipContent)
			{
				if ($dipId === 'docLinks' || $dipId === 'doclinks')
				{
					$this->addDocLinks($newRec, $this->table, $dipContent);
					continue;
				}
				if ($dipId === 'groups' && $this->table->tableId() === 'e10.persons.persons')
				{

					continue;
				}

				if ($dipId === 'attachments')
				{
					//$this->addAttachments($newRec, $this->table, $dipContent);
					continue;
				}

				$listDefinition = $this->table->listDefinition($dipId);
				if ($listDefinition === NULL)
				{
					//$this->err("Invalid list '$dipId' in file '{$this->pkgFileName}'");
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
						return $this->setStatus(ObjectsPut::psListItemIsNotArray, "List item '$dipId' is not array: " . json_encode($lr));
					}
					$rd = $lr;
					$rd['ndx'] = 0;
					//$this->checkColumnValues($this->table, $newRec);
					$this->checkPrimaryKeys($listTable, $rd);
					$listData [] = $rd;
				}

				$listObject->setRecData($this->table, $dipId, $newRec);
				$listObject->saveData($listData);
			}
		}

		// -- "close" document
		$docStatesDef = $this->app->model()->tableProperty ($this->table, 'states');
		if ($docStatesDef)
		{
			$f = $this->table->getTableForm ('edit', $newItemNdx);

			if (!isset($f->recData[$docStatesDef['stateColumn']]))
				$f->recData[$docStatesDef['stateColumn']] = 4000;
			if (!isset($f->recData[$docStatesDef['mainStateColumn']]))
				$f->recData[$docStatesDef['mainStateColumn']] = 2;

			if ($f->checkAfterSave())
				$this->table->dbUpdateRec ($f->recData);

			$f->checkAfterSave();
			$this->table->checkDocumentState ($f->recData);
			$this->table->dbUpdateRec ($f->recData);

			$this->doImportDataRows ($f->recData);

			$this->table->checkAfterSave2 ($f->recData);

			//$this->table->docsLog ($f->recData['ndx']);

			if (isset($f->recData['id']))
				$this->result ['id'] = $f->recData['id'];
			if (isset($f->recData['docNumber']))
				$this->result ['docNumber'] = $f->recData['docNumber'];

			$this->result ['recData'] = $f->recData;
		}

		$this->db()->commit();

		return $this->status;
	}

	public function addDocLinks ($ownerRecData, $ownerTable, $docLinks)
	{
		$tableDocLinks = $this->app->table ('e10.base.doclinks');
		$deletedIds = [];
		$allLinksTotal = $this->app->cfgItem ('e10.base.doclinks', NULL);

		if (!$allLinksTotal || !isset($allLinksTotal[$this->table->tableId()]))
			return;

		$allLinks = $allLinksTotal[$this->table->tableId()];
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

	protected function doImportDataRows ($headRecData)
	{
		if (!isset($this->data['dataRows']))
			return;

		foreach ($this->data['dataRows'] as $dataId => $data)
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

	protected function doDelete ()
	{
		if ($this->status !== ObjectsPut::psOK)
			return;

		$newRec = $this->data['rec'];
		$this->checkPrimaryKeys($this->table, $newRec);

		$accessLevel = $this->table->checkAccessToDocument ($newRec);
		if ($accessLevel !== 2)
			return $this->setStatus(ObjectsPut::psUnauthorized, 'Access denied');

		$docStatesDef = $this->app->model()->tableProperty ($this->table, 'states');
		if ($docStatesDef)
		{
			$f = $this->table->getTableForm ('edit', $newRec['ndx']);

			$f->recData[$docStatesDef['stateColumn']] = 9800;
			$f->recData[$docStatesDef['mainStateColumn']] = 4;

			if ($f->checkAfterSave())
				$this->table->dbUpdateRec ($f->recData);

			$f->checkAfterSave();
			$this->table->checkDocumentState ($f->recData);
			$this->table->dbUpdateRec ($f->recData);
			$this->table->checkAfterSave2 ($f->recData);

			$this->table->docsLog ($f->recData['ndx']);
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
				error_log("INVALID COLUMN: `{$colId}` in table `".$table->tableId()."`");
				$this->result ['errors'][] = "INVALID COLUMN: column {$colId}";
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
				if ($dstCol === 'syncNdx' && isset($this->data['syncSrc']))
					array_push($q, ' AND [syncSrc] = %i', $this->data['syncSrc']);

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

	protected function response ()
	{
		if ($this->status === ObjectsPut::psOK)
			$this->result ['status'] = 1;
		$r = new Response($this->app, json_encode($this->result, JSON_PRETTY_PRINT));
		$r->setMimeType('application/json');
		return $r;
	}

	public function run ()
	{
		$this->result ['status'] = 0;

		//$this->operation = $this->app->requestPath(2);

		$this->parseData();
		$this->doImport();

		return $this->response();
	}

	public function importData($table, $data)
	{
		$this->result ['status'] = 0;

		$this->table = $table;
		$this->data = $data;

		$this->doImport();
	}
}
