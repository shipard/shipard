<?php

namespace e10pro\ofre\libs;
use \Shipard\Viewer\TableViewDetail;

/**
 * class ViewDetailOffice
 */
class ViewDetailOffice extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.ofre.libs.dc.OfficeCore');
  }
}
