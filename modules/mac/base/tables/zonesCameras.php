<?php

namespace mac\base;


use \e10\TableForm, \e10\DbTable;


/**
 * Class TableZonesCameras
 * @package mac\base
 */
class TableZonesCameras extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.base.zonesCameras', 'mac_base_zonesCameras', 'Kamery v zónach');
	}
}


/**
 * Class FormZoneCamera
 * @package mac\base
 */
class FormZoneCamera extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('camera', TableForm::coColW12);
			$this->closeRow();
		$this->closeForm ();
	}
}

