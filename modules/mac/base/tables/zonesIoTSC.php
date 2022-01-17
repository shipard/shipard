<?php

namespace mac\base;


use \e10\TableForm, \e10\DbTable;


/**
 * Class TableZonesIotSC
 * @package mac\base
 */
class TableZonesIotSC extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.base.zonesIoTSC', 'mac_base_zonesIoTSC', 'Senzory a ovládací prvky v zónach');
	}
}


/**
 * Class FormZoneIoTSC
 * @package mac\base
 */
class FormZoneIoTSC extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('rowType', TableForm::coColW3);
				if ($this->recData['rowType'] == 0)
					$this->addColumnInput ('iotSensor', TableForm::coColW9);
				elseif ($this->recData['rowType'] == 1)
					$this->addColumnInput ('iotControl', TableForm::coColW9);
				else
					$this->addColumnInput ('iotSetup', TableForm::coColW9);
			$this->closeRow();
		$this->closeForm ();
	}
}

