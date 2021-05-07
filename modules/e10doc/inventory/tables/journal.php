<?php

namespace E10Doc\Inventory;

use \E10\DbTable;

class TableJournal extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.inventory.journal", "e10doc_inventory_journal", "Pohyby zásob");
	}

	public function doIt ($recData)
	{
		$engine = new \e10doc\inventory\libs\InventoryStatesEngine($this->app());

		$engine->setDocument($recData);
		$engine->clearDocumentRows();

		if ($recData ['docState'] != 4000)
			return;
		if ($recData ['warehouse'] == 0)
			return;

		$engine->createDocumentJournal();
	}
}
