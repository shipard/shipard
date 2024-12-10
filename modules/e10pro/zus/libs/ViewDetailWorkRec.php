<?php

namespace e10pro\zus\libs;
use \Shipard\Viewer\TableViewDetail;


/**
 * class ViewDetailWorkRec
 */
class ViewDetailWorkRec extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.zus.libs.dc.DCWorkRec');
	}

	public function createToolbar ()
	{
		return [];
	}
}

