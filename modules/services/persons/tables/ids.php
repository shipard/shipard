<?php

namespace services\persons;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail;


/**
 * Class TableIds
 */
class TableIds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.persons.ids', 'services_persons_ids', 'ID');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['street']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['city']];

		return $hdr;
	}
}
