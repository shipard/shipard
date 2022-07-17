<?php

namespace e10pro\condo\libs;
use \Shipard\Viewer\TableViewDetail;

/**
 * Class ViewDetailFlat
 */
class ViewDetailFlat extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.condo.libs.dc.FlatCore');
  }
}
