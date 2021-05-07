<?php

namespace lib\objects;
use E10\Service, E10\Response, E10\utils;


class ObjectsView extends \lib\objects\ObjectsList
{
	protected function createIterator ()
	{
		$classId = $this->app->requestPath(3);
		$iteratorClass = $this->app->cfgItem ('registeredClasses.objectsViews.'.$classId, FALSE);

		if ($iteratorClass !== FALSE)
		{
			$classId = $iteratorClass['classId'];
			$this->objectsIterator = $this->app->createObject($classId);
			$this->objectsIterator->setTable(NULL);
			$this->table = $this->objectsIterator->table;
			$this->objectsIterator->setParams($this->params);
		}
	}

	protected function checkAccess ()
	{
		return TRUE;
	}

	public function checkErrors ()
	{
		return FALSE;
	}
}
