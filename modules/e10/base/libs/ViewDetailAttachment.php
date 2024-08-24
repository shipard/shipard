<?php
namespace e10\base\libs;
use \Shipard\Viewer\TableViewDetail;



/**
 * class ViewDetailAttachment
 */
class ViewDetailAttachment extends TableViewDetail
{
  public function createDetailContent ()
	{
		$this->addDocumentCard('e10.base.libs.dc.DCAttachment');
	}
}
