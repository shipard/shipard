<?php

namespace lib\objects;
use \Shipard\Base\Service, \Shipard\Application\Response, \Shipard\Application\DataModel;


/**
 * Class ObjectsPut
 * @package lib\objects
 */
class ObjectsCall extends Service
{
	const psOK = 0, psBadFunctionId = 1;

	protected $status = ObjectsPut::psOK;
	protected $functionId;
	protected $functionClass = FALSE;

	protected $result = FALSE;

	protected function setStatus ($status, $msg = '')
	{
		$this->status = $status;
		$this->result ['errorCode'] = $status;
		$this->result ['errorMsg'] = $msg;

		return $status;
	}

	protected function parseParams ()
	{
		$this->functionId = $this->app->requestPath(3);
		$this->functionClass = $this->app->cfgItem ('registeredClasses.objectsCalls.'.$this->functionId, FALSE);

		if (!$this->functionClass)
			$this->setStatus(self::psBadFunctionId, 'Invalid function id');
	}

	protected function doIt ()
	{
		$classId = $this->functionClass['classId'];
		$object = $this->app->createObject($classId);
		$object->run ();
		$this->result = $object->result;
	}

	protected function response ()
	{
		if ($this->status === self::psOK)
			$this->result ['status'] = 1;

		if (isset($this->result ['forceTextData']))
		{
			$r = new Response($this->app, $this->result ['forceTextData']);
			$r->setMimeType('text/plain');
			if (isset($this->result ['forceTextDataSaveFileName']))
				$r->setSaveFileName($this->result ['forceTextDataSaveFileName'], 'attachment');
			return $r;
		}

		$r = new Response($this->app, json_encode($this->result, JSON_PRETTY_PRINT));
		$r->setMimeType('application/json');
		return $r;
	}

	public function run ()
	{
		$this->result ['status'] = self::psOK;

		$this->parseParams();
		if ($this->status === self::psOK)
			$this->doIt();

		return $this->response();
	}
}
