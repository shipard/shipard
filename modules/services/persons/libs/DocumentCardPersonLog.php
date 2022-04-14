<?php

namespace services\persons\libs;


/**
 * class DocumentCardPersonLog
 */
class DocumentCardPersonLog extends \Shipard\Base\DocumentCard
{
	function addLog()
	{
		$this->addContent ('body', ['type' => 'viewer', 'table' => 'services.persons.log', 'viewer' => 'services.persons.libs.ViewerPersonLog', 'params' => ['person' => $this->recData['ndx']]]);
	}

	public function createContentBody ()
	{
		$this->addLog();
	}	

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
