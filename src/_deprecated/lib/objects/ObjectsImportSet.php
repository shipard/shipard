<?php

namespace lib\objects;
use E10\Service, E10\Response, E10\DataModel;


/**
 * Class ObjectsImportSet
 * @package lib\objects
 */
class ObjectsImportSet extends Service
{
	const psOK = 0;

	protected $status = ObjectsPut::psOK;
	protected $tableId;
	protected $dataString = '';
	protected $data = NULL;

	/** @var \e10\DbTable */
	protected $table = NULL;

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

		$this->table = $this->app->table($tableId);
		if ($this->table === NULL)
			return $this->setStatus(ObjectsPut::psInvalidTableId, "Table '$tableId' not found");

		$this->dataString = $this->app->postData();
		if ($this->dataString == '')
			return $this->setStatus(ObjectsPut::psEmptyData, 'Empty POST data');

		$this->data = json_decode($this->dataString, TRUE);
		if ($this->data === NULL)
			return $this->setStatus(ObjectsPut::psParseError, 'Parse error: '.json_last_error_msg());
	}

	protected function doIt ()
	{
		foreach ($this->data as $item)
		{
			$o = new \lib\objects\ObjectsImport($this->app());
			$o->importData($this->table, $item);

			if (!isset($o->result['status']) || $o->result['status'] != ObjectsPut::psOK)
			{
				$this->result['msgs'][] = $o->result;
			}
		}
	}

	protected function response ()
	{
		if ($this->status === self::psOK)
			$this->result ['status'] = 1;
		$r = new Response($this->app, json_encode($this->result, JSON_PRETTY_PRINT)."\n");
		$r->setMimeType('application/json');
		return $r;
	}

	public function run ()
	{
		$this->result ['status'] = self::psOK;

		$this->parseData();

		if ($this->status === self::psOK)
			$this->doIt();

		return $this->response();
	}
}
