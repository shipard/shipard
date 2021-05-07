<?php

namespace lib\ui;

use \E10\uiutils, \e10\E10Object, \lib\ui\FormDocument, \E10\str, \E10\TableForm, \E10\DbTable, E10\Window;


/**
 * Class FormDocumentSimple
 * @package lib\ui
 */
class FormDocumentSimple extends FormDocument
{
	public function checkAfterDone ()
	{
	}

	public function checkBeforeDone ()
	{
	}

	public function doIt ()
	{
		parent::doIt();

		$this->load();

		switch($this->operation)
		{
			case 'open': return $this->doItOpen ();
			case 'done': return $this->doItDone ();
		}

		return $this->doItNew();
	}

	public function doItNew ()
	{
		return TRUE;
	}

	public function doItDone ()
	{
		$this->checkBeforeDone();
		$this->save ();
		$this->checkAfterDone();
	}

	public function doItOpen ()
	{
	}

	protected function load ($pk = FALSE)
	{
		parent::load($pk);

		if (isset($this->postData['formData']))
		{
			foreach ($this->postData['formData']['recData'] as $colId => $colValue)
			{
				$this->recData[$colId] = $colValue;
			}
		}
	}

	protected function save ()
	{
		$needLog = 0;

		$this->table->checkBeforeSave($this->recData);
		if ($this->recData['ndx'])
		{ // update
			$pk = $this->table->dbUpdateRec ($this->recData);
			$this->recData = $this->table->loadItem ($pk);
		}
		else
		{ // insert
			$pk = $this->table->dbInsertRec ($this->recData);
			$this->recData = $this->table->loadItem ($pk);
			$this->pk = $pk;
			$needLog = 1;
		}

		$this->table->checkAfterSave2 ($this->recData);

		// -- save event to log
		if ($needLog)
		{
			$this->table->docsLog ($pk);
		}
	}
}
