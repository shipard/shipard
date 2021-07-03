<?php

namespace lib\ui;

use \Shipard\Form\Window;


class AttachmentsWindow extends Window
{
	public function renderForm ()
	{
		$tableId = $this->app()->testGetParam('tableid');
		$recId = $this->app()->testGetParam('recid');

		//$this->setFlag ('maximize', 1);

		$this->openForm ();

		$c = '';

		$c .= $this->app()->ui()->addAttachmentsInputCode($tableId, $recId, NULL);


		$this->appendElement ($c, NULL);

		$this->closeForm ();
	}
}
