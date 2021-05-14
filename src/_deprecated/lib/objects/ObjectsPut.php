<?php

namespace lib\objects;
use \Shipard\Base\Service, E10\Response, E10\DataModel;


/**
 * Class ObjectsPut
 * @package lib\objects
 */
class ObjectsPut extends Service
{
	const psOK = 0, psEmptyData = 1, psParseError = 2, psUnknownTable = 3, psInvalidTableId = 4,
			psUnknownColumn = 5, psRecNotFound = 6, psInsertFailed = 7, psUnauthorized = 8, psListItemIsNotArray = 9;

	protected $status = ObjectsPut::psOK;
	protected $operation;
	protected $dataString = '';
	protected $data = NULL;
	protected $table = NULL;

	protected $result = [];

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

	protected function doInsertUpdate ()
	{
		if ($this->status !== ObjectsPut::psOK)
			return;

		$newRec = $this->data['rec'];
		$this->checkPrimaryKeys($this->table, $newRec);

		$accessLevel = $this->table->checkAccessToDocument ($newRec);
		if ($accessLevel !== 2)
			return $this->setStatus(ObjectsPut::psUnauthorized, 'Access denied');

		if ($this->operation === 'insert')
		{
			$this->table->checkNewRec($newRec);
			$newItemNdx = $this->table->dbInsertRec($newRec);
			if (!$newItemNdx)
				return $this->setStatus(ObjectsPut::psInsertFailed, 'Insert failed');
			$newRec['ndx'] = $newItemNdx;
			$this->result ['ndx'] = $newItemNdx;
		}
		else
		{ // update
			$newItemNdx = $this->table->dbUpdateRec($newRec);
		}

		// lists
		foreach ($this->data as $dipId => $dipContent)
		{
			if ($dipId === 'rec' || $dipId === 'table')
				continue;

			if ($dipId === 'docLinks')
			{
				//$this->addDocLinks($newRec, $this->table, $dipContent);
				continue;
			}

			if ($dipId === 'attachments')
			{
				//$this->addAttachments($newRec, $this->table, $dipContent);
				continue;
			}

			$listDefinition = $this->table->listDefinition ($dipId);
			if ($listDefinition === NULL)
			{
				//$this->err("Invalid list '$dipId' in file '{$this->pkgFileName}'");
				continue;
			}

			$listObject = $this->app->createObject ($listDefinition ['class']);

			$listTable = NULL;
			if (isset ($listDefinition['table']))
				$listTable = $this->app->table ($listDefinition['table']);

			$listData = [];
			foreach ($dipContent as $lr)
			{
				if (!is_array($lr))
				{
					return $this->setStatus(ObjectsPut::psListItemIsNotArray, "List item '$dipId' is not array: ".json_encode($lr));
				}
				$rd = $lr;
				$rd['ndx'] = 0;
				//$this->checkColumnValues($this->table, $newRec);
				$this->checkPrimaryKeys($listTable, $rd);
				$listData [] = $rd;
			}

			$listObject->setRecData ($this->table, $dipId, $newRec);
			$listObject->saveData ($listData);
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
			$this->table->checkAfterSave2 ($f->recData);

			$this->table->docsLog ($f->recData['ndx']);

			if (isset($f->recData['id']))
				$this->result ['id'] = $f->recData['id'];
			if (isset($f->recData['docNumber']))
				$this->result ['docNumber'] = $f->recData['docNumber'];

			// -- print after document confirm
			$printAfterConfirm = $this->app->testGetParam('printAfterConfirm');
			if ($printAfterConfirm === '1' || $printAfterConfirm === '2')
			{
				$printCfg = ['printMode' => $printAfterConfirm];

				$printerType = $this->app->testGetParam('printerType');
				if ($printerType !== '')
					$printCfg['printerType'] = $printerType;

				$this->table->printAfterConfirm($printCfg, $f->recData, $docStatesDef);
				if (isset($printCfg['posReports']))
					$this->result['posReports'] = $printCfg['posReports'];
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
			if ($colDef['type'] !== DataModel::ctInt && $colDef['type'] !== DataModel::ctEnumInt && $colId !== 'ndx')
				continue;
			if ($colValue !== '' && $colValue[0] === '@')
			{
				if (!isset($colDef['reference']) && $colId !== 'ndx')
				{
					error_log ("BAD REFERENCE: column {$colId}\n");
					continue;
				}
				if ($colId === 'ndx')
					$tableRef = $table;
				else
					$tableRef = $this->app->table ($colDef['reference']);
				$q = "SELECT ndx FROM [".$tableRef->sqlName ()."] WHERE [id] = %s";
				$refRow = $this->app->db()->query ($q, substr($colValue, 1))->fetch();
				if (isset($refRow['ndx']))
					$recData[$colId] = $refRow['ndx'];
				else
				{
					$recData[$colId] = 0;
					error_log("ERROR: primary key '{$colValue}' not found\n");
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

		$this->operation = $this->app->requestPath(2);

		$this->parseData();

		if ($this->operation === 'delete')
			$this->doDelete();
		else
			$this->doInsertUpdate();

		return $this->response();
	}
}
