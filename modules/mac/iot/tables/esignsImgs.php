<?php

namespace mac\iot;
use \Shipard\Table\DbTable;


/**
 * Class TableESignsImgs
 */
class TableESignsImgs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.esignsImgs', 'mac_iot_esignsImgs', 'Obrázky E-cedulek');
	}
}
