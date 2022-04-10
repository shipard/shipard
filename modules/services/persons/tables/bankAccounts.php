<?php

namespace services\persons;
use \Shipard\Table\DbTable;

/**
 * Class TableBankAccounts
 */
class TableBankAccounts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.persons.bankAccounts', 'services_persons_bankAccounts', 'Bankovní účty');
	}
}
