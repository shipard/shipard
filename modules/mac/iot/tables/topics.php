<?php

namespace mac\iot;
use \e10\DbTable;


/**
 * Class TableTopics
 * @package mac\iot
 */
class TableTopics extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.topics', 'mac_iot_topics', 'Témata zpráv');
	}
}
