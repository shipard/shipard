<?php

namespace Shipard\Base;


class ListData
{
	var $table;
	var $item;
	var $ok = 0;
	var $listId;
	var $listOp;
	var $headerData;
	var $objectData = array ();

	public function __construct ($table, $listId, $listOp)
	{
		$this->table = $table;
		$this->listId = $listId;
		$this->listOp = $listOp;
	}

	public function finish ($pk)
	{
		$listDefinition = $this->table->listDefinition ($this->listId);
		$listObject = $this->table->app()->createObject ($listDefinition ['class']);
		//$listObject->setRecord ('attachments', $formData);

		$listObject->setRecData ($this->table, $this->table->app()->requestPath (3), $this->table->loadItem (intval($pk)));

		switch ($this->listOp)
		{
			case 'widget':
							$c = $listObject->createHtmlCode (TRUE);
							break;
			case 'append':
              $formElementId = $this->table->app()->testGetParam ('formElementId');
              $listObject->fid = $formElementId;
              $c = $listObject->appendRowCode ();
              break;
		}
		$this->setCode ($c);

		$this->ok = 1;
	}

	public function setCode ($main)
	{
		$this->objectData ['htmlContent'] = $main;
	}
}
