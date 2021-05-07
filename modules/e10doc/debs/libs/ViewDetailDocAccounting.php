<?php

namespace e10doc\debs\libs;
use \Shipard\Viewer\TableViewDetail;


class ViewDetailDocAccounting extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.core.dc.Accounting');
	}
}
