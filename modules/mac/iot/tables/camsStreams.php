<?php

namespace mac\iot;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;


/**
 * class TableCamsStreams
 */
class TableCamsStreams extends DbTable
{
	CONST
		stWebRTC = 0;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.camsStreams', 'mac_iot_camsStreams', 'Streamy z kamer');
	}
}


/**
 * class FormCamStream
 */
class FormCamStream  extends TableForm
{
	public function renderForm ()
  {
    $this->openForm (TableForm::ltGrid);
      $this->openRow ();
        $this->addColumnInput ('streamType', TableForm::coColW3);
        $this->addColumnInput ('streamUrl', TableForm::coColW9);
      $this->closeRow ();
    $this->closeForm ();
	}
}

