<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableViewDetail;


/**
 * class ViewAddressTechnicalDetail
 */
class ViewAddressTechnicalDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10.persons.libs.dc.DCAddressTechnical');
	}
}
