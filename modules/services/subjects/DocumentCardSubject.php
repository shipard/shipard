<?php

namespace services\subjects;



/**
 * Class DocumentCardSubject
 * @package services\subjects
 */
class DocumentCardSubject extends \e10\DocumentCard
{
	public function createContentHeader ()
	{
	}

	public function createContentBody ()
	{
		$e = new \lib\content\ItemDetail($this->app());
		$e->load($this->table, $this->recData);
		$this->addContent ('body', $e->contentPart);

		$h = ['t' => 'text', 'v' => 'hodnota'];
		$t = [];

		$t[] = ['t' => 'Platné OD', 'v' => $this->recData['validFrom'], '_options' => ['cellClasses' => ['t' => 'width30']]];
		$t[] = ['t' => 'Platné DO', 'v' => $this->recData['validTo']];

		$this->addContent('body', [
			'table' => $t, 'header' => $h, 'pane' => 'e10-pane e10-pane-table', 'title' => 'Platnost',
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);
	}

	public function createContentTitle ()
	{
	}

	public function createContent ()
	{
		$this->createContentHeader ();
		$this->createContentBody ();
		$this->createContentTitle ();
	}
}
