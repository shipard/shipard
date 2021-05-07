<?php

namespace mac\lan;


use \e10\TableForm, \e10\DbTable;

/**
 * Class TableDeviceTypesPorts
 * @package mac\lan
 */
class TableDeviceTypesPorts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.deviceTypesPorts', 'mac_lan_deviceTypesPorts', 'Porty typů síťových zařízení');
	}
}


/**
 * Class FormDeviceTypePort
 * @package mac\lan
 */
class FormDeviceTypePort extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->addColumnInput ('portKind', TableForm::coColW2);
			$this->addColumnInput ('portsCount', TableForm::coColW3);
			$this->addColumnInput ('portIdMask', TableForm::coColW3);
			$this->addColumnInput ('note', TableForm::coColW4);
		$this->closeForm ();
	}
}
