<?php

namespace lib\objects;
use E10\Service, E10\Response;


/**
 * Class ObjectsManager
 * @package lib\objects
 */
class ObjectsManager extends Service
{
	var $operation = '';

	protected function detectParams ()
	{
		$this->operation = $this->app->requestPath(2);
	}

	public function run ()
	{
		$this->detectParams();

		$service = $this->service();
		if ($service === FALSE)
			return new Response ($this->app, 'invalid operation type', 404);

		return $service->run ();
	}

	protected function service ()
	{
		switch ($this->operation)
		{
			case	'list': return new \lib\objects\ObjectsList ($this->app);
			case	'view': return new \lib\objects\ObjectsView ($this->app);
			case	'insert': return new \lib\objects\ObjectsPut ($this->app);
			case	'update': return new \lib\objects\ObjectsPut ($this->app);
			case	'delete': return new \lib\objects\ObjectsPut ($this->app);
			case	'import': return new \lib\objects\ObjectsImport ($this->app);
			case	'importSet': return new \lib\objects\ObjectsImportSet ($this->app);
			case	'call': return new \lib\objects\ObjectsCall ($this->app);
			case	'alert': return new \lib\objects\ObjectsAlert($this->app);
		}

		return FALSE;
	}
}
