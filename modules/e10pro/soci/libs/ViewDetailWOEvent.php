<?php

namespace e10pro\soci\libs;
use \Shipard\Viewer\TableViewDetail;

/**
 * Class ViewDetailWOEvent
 */
class ViewDetailWOEvent extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.soci.libs.dc.WOEventCore');
  }
}
