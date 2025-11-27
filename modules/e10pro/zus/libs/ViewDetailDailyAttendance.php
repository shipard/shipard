<?php

namespace e10pro\zus\libs;
use \Shipard\Viewer\TableViewDetail;


/**
 * class ViewDetailDailyAttendance
 */
class ViewDetailDailyAttendance extends TableViewDetail
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

