<?php

namespace lib\ui;

use \E10\uiutils, \E10\str, \E10\TableForm, \E10\DbTable, E10\Window;


/**
 * Class ScanToDocumentWindow
 * @package lib\ui
 */
class ScanToDocumentWindow extends Window
{
	public function renderForm ()
	{
		$tableId = $this->app()->testGetParam('tableid');
		$recId = $this->app()->testGetParam('recid');

		//$this->setFlag ('maximize', 1);

		$this->openForm ();

		$c = '';

		//$c .= $this->app()->ui()->addAttachmentsInputCode($tableId, $recId, NULL);


		$this->appendElement ($c, NULL);

		$this->closeForm ();
	}
}
